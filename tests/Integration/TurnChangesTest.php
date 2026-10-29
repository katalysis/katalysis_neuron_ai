<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Chat\TurnChanges;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ApproveAll;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

/**
 * BF-7: the agent records the pages and blocks each turn changed (TurnChanges, a listener on Neuron's ToolCalled,
 * brought forward from P3.6; plans/P2.7.md §5). Real turns with FakeAIProvider calling real tools on throwaway pages:
 * no AI provider call.
 */
final class TurnChangesTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    /** @var int[] */
    private array $chatIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
    }

    protected function tearDown(): void
    {
        foreach ($this->chatIds as $id) {
            $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $this->chatIds = [];
        $this->pages->restore();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function newChat(): int
    {
        $chat = new Chat();
        $chat->setSessionId('neuron-test-bf7-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(USER_SUPER_ID);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        return (int) $chat->getId();
    }

    private static function call(string $tool, array $inputs): ToolCallMessage
    {
        return new ToolCallMessage(null, [new ToolCall($tool, 'call_' . bin2hex(random_bytes(3)), $inputs)]);
    }

    /**
     * A formatting-only edit of the block: <p> becomes <h2>.
     */
    private function editBlock(int $cID, int $bID): ToolCallMessage
    {
        $html = trim((string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$bID]));

        return self::call('update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => (string) preg_replace('/^<p>(.*)<\/p>$/s', '<h2>$1</h2>', $html)],
            'changeMode' => 'formatting_only',
        ]);
    }

    private function agent(FakeAIProvider $provider): ConcreteCmsAgent
    {
        return (new ConcreteCmsAgent())->setChatId($this->newChat())->setAiProvider($provider);
    }

    public function testATurnRecordsThePageAndTheBlocksItChangedWithTheirNewIDs(): void
    {
        $page = $this->pages->create('Turn changes');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);

        $agent = $this->agent(new FakeAIProvider(
            self::call('get_page_content', ['pageID' => $cID]),
            $this->editBlock($cID, $b1),
            new AssistantMessage('Done.')
        ));
        $this->assertSame('Done.', ApproveAll::turn($agent, 'Make the first paragraph a heading.'));

        $changes = $agent->getTurnChanges();
        $this->assertCount(1, $changes, 'one page, and the read is not a change');
        $this->assertSame($cID, $changes[0]['pageID']);
        $this->assertSame((string) $page->getCollectionPath(), $changes[0]['path']);
        $this->assertSame((string) $page->getCollectionName(), $changes[0]['name']);
        $this->assertSame(['update_block_in_page_area'], $changes[0]['tools']);
        $newID = $this->pages->blockIDs($cID)[0];
        $this->assertNotSame($b1, $newID, 'the first change to a block in a draft copies it (plans/BF-7.md §2)');
        $this->assertSame([$newID], $changes[0]['blockIDs']);
        $this->assertSame([(string) $b1 => $newID], $changes[0]['replacedBlockIDs']);
    }

    public function testARefusedWriteIsNotRecorded(): void
    {
        $page = $this->pages->create('Turn changes, refused');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);

        $agent = $this->agent(new FakeAIProvider(
            self::call('update_block_in_page_area', ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => '<p>Invented text.</p>']]),
            new AssistantMessage('It was refused.')
        ));
        ApproveAll::turn($agent, 'Rewrite it.');

        $this->assertSame([], $agent->getTurnChanges());
    }

    public function testEachTurnStartsEmpty(): void
    {
        $page = $this->pages->create('Turn changes, two turns');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $provider = new FakeAIProvider($this->editBlock($cID, $b1), new AssistantMessage('Done.'), new AssistantMessage('Hello.'));
        $agent = $this->agent($provider);

        ApproveAll::turn($agent, 'Change it.');
        $this->assertCount(1, $agent->getTurnChanges());
        $agent->handleUserMessage('Hello.');
        $this->assertSame([], $agent->getTurnChanges());
    }

    public function testTheRecordSurvivesATurnThatFailsAfterTheWrite(): void
    {
        $page = $this->pages->create('Turn changes, failed turn');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $provider = new class ($this->editBlock($cID, $b1)) extends FakeAIProvider {
            public function chat(Message ...$messages): ProviderResponse
            {
                if ($this->getCallCount() >= 1) {
                    throw new \RuntimeException('The provider went away.');
                }

                return parent::chat(...$messages);
            }
        };
        $agent = $this->agent($provider);

        $this->assertSame(ConcreteCmsAgent::ERROR_REPLY, ApproveAll::turn($agent, 'Change it.'));

        $changes = $agent->getTurnChanges();
        $this->assertCount(1, $changes, 'the write happened before the failure, so the panel must still learn of it');
        $this->assertSame($cID, $changes[0]['pageID']);
    }

    public function testACallDeliveredTwiceIsRecordedOnce(): void
    {
        $page = $this->pages->create('Turn changes, duplicate');
        $cID = (int) $page->getCollectionID();
        [$b1, $b2] = $this->pages->blockIDs($cID);
        $changes = new TurnChanges();

        $call = new ToolCall('update_block_in_page_area', 'call_same', ['pageID' => $cID, 'blockID' => $b1]);
        $call->setResult((string) json_encode(['blockID' => $b1, 'versionState' => ['status' => 'unpublished', 'pageID' => $cID, 'versionID' => 2]]));
        $changes(new ToolCalled($call));
        $changes(new ToolCalled($call));
        // Neuron may emit the event again on a retry: the call ID is the key (src/Observability/AGENTS.md:28).
        $again = new ToolCall('update_block_in_page_area', 'call_same', ['pageID' => $cID, 'blockID' => $b2]);
        $again->setResult((string) json_encode(['blockID' => $b2, 'versionState' => ['status' => 'unpublished', 'pageID' => $cID, 'versionID' => 2]]));
        $changes(new ToolCalled($again));

        $all = $changes->all();
        $this->assertCount(1, $all);
        $this->assertSame(['update_block_in_page_area'], $all[0]['tools']);
        $this->assertSame([$b1], $all[0]['blockIDs']);
    }

    /** P3.6a: a change to a global area through the page is named on the page's line. */
    public function testAGlobalAreaChangedThroughThePageIsNamed(): void
    {
        $page = $this->pages->create('Turn changes, global area');
        $cID = (int) $page->getCollectionID();
        $changes = new TurnChanges();

        $call = new ToolCall('update_block_in_page_area', 'call_shared', ['pageID' => $cID, 'blockID' => 1]);
        $call->setResult((string) json_encode([
            'blockID' => 2,
            'versionState' => ['status' => 'unpublished', 'pageID' => $cID, 'versionID' => 2],
            'sharedArea' => ['name' => 'Header Banner', 'kind' => 'global area', 'pages' => 12, 'examples' => [], 'notice' => '…'],
        ]));
        $changes(new ToolCalled($call));

        $this->assertSame(
            'Changed: ' . $page->getCollectionName() . ' (' . $page->getCollectionPath() . '), unpublished version 2 (with the global area "Header Banner", shown on every page that has it).',
            $changes->line()
        );
    }

    public function testCallsWithoutAnIDAreKeyedByNameAndInputs(): void
    {
        $page = $this->pages->create('Turn changes, no call ID');
        $cID = (int) $page->getCollectionID();
        [$b1, $b2] = $this->pages->blockIDs($cID);
        $changes = new TurnChanges();

        foreach ([$b1, $b1, $b2] as $bID) {
            $call = new ToolCall('update_block_in_page_area', null, ['pageID' => $cID, 'blockID' => $bID]);
            $call->setResult((string) json_encode(['blockID' => $bID, 'versionState' => ['status' => 'unpublished', 'pageID' => $cID, 'versionID' => 2]]));
            $changes(new ToolCalled($call));
        }

        $this->assertSame([$b1, $b2], $changes->all()[0]['blockIDs']);
    }

    public function testWhatIsRecordedIsRedacted(): void
    {
        $page = $this->pages->create('Redaction sk-bf7test1234567890abcdef');
        $cID = (int) $page->getCollectionID();
        $changes = new TurnChanges();

        $call = new ToolCall('update_page', 'call_redact', ['pageID' => $cID]);
        $call->setResult((string) json_encode(['id' => $cID, 'versionState' => ['status' => 'unpublished', 'pageID' => $cID, 'versionID' => 2]]));
        $changes(new ToolCalled($call));

        $recorded = (string) json_encode($changes->all());
        $this->assertStringNotContainsString('sk-bf7test1234567890abcdef', $recorded);
        $this->assertStringContainsString('Redaction', $recorded);
    }

    public function testAReadAndAFailedWriteAreIgnored(): void
    {
        $page = $this->pages->create('Turn changes, ignored');
        $cID = (int) $page->getCollectionID();
        $changes = new TurnChanges();

        $read = new ToolCall('get_page', 'call_read', ['pageID' => $cID]);
        $read->setResult((string) json_encode(['id' => $cID, 'versionState' => ['status' => 'unpublished', 'pageID' => $cID, 'versionID' => 2]]));
        $changes(new ToolCalled($read));
        $failed = new ToolCall('update_page', 'call_failed', ['pageID' => $cID]);
        $failed->setResult('Error executing tool: no.');
        $changes(new ToolCalled($failed));
        $noResult = new ToolCall('update_page', 'call_pending', ['pageID' => $cID]);
        $changes(new ToolCalled($noResult));

        $this->assertSame([], $changes->all());
    }
}
