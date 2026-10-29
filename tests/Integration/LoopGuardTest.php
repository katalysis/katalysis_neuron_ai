<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

/**
 * P3.9-3 (B-ERR-3, Glyn's rule): the loop guard. After the user denies a call, the same tool is refused for the rest of
 * that turn, whatever its inputs, and the model is told to ask the user what they want instead (scenario 35 in P3.6-2:
 * three create_page cards after three Denies). A call that failed isn't run again with the same inputs, and after one
 * alternative attempt that also failed, the tool isn't run again in that turn: the model is told to report and stop.
 * Real turns with FakeAIProvider on throwaway pages; no AI provider call.
 */
final class LoopGuardTest extends ConcreteTestCase
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
            (new ConcreteCmsAgent())->setChatId($id)->discardRun();
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
        $chat->setSessionId('neuron-test-p393-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(USER_SUPER_ID);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        return (int) $chat->getId();
    }

    private function agent(int $chatId, FakeAIProvider $provider): ConcreteCmsAgent
    {
        $this->app()->make(EntityManagerInterface::class)->clear();
        CacheLocal::flush();

        return (new ConcreteCmsAgent())->setChatId($chatId)->setAiProvider($provider);
    }

    private static function call(string $tool, array $inputs): ToolCall
    {
        return new ToolCall($tool, 'call_' . bin2hex(random_bytes(4)), $inputs);
    }

    private static function calls(ToolCall ...$calls): ToolCallMessage
    {
        return new ToolCallMessage(null, $calls);
    }

    /** @return array<string, string> status by call ID */
    private function statuses(int $chatId): array
    {
        return array_column($this->db()->fetchAllAssociative('SELECT callId, status FROM KatalysisNeuronAiApprovals WHERE chatId = ? ORDER BY id', [$chatId]), 'status', 'callId');
    }

    /** @return array<string, string> each call's result as the provider was sent it, by call ID */
    private static function sentResults(FakeAIProvider $provider): array
    {
        $results = [];
        foreach ($provider->getRecorded() as $request) {
            foreach ($request->messages as $message) {
                if ($message instanceof ToolResultMessage) {
                    foreach ($message->getToolCalls() as $call) {
                        $results[(string) $call->getCallId()] = (string) $call->getResult();
                    }
                }
            }
        }

        return $results;
    }

    public function testAfterADenyTheSameToolIsRefusedForTheRestOfTheTurnWhateverItsInputs(): void
    {
        $page = $this->pages->create('Loop guard deny');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $first = self::call('update_page', ['pageID' => $cID, 'name' => 'First try']);
        $second = self::call('update_page', ['pageID' => $cID, 'name' => 'Second try']);
        $other = self::call('set_page_attribute_value', ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Other tool']);

        $this->agent($chatId, new FakeAIProvider(self::calls($first)))->handleUserMessage('Rename it.');

        // Deny: the model asks again with other inputs, which is refused without a card, then for another tool, which
        // gets its card as usual.
        $provider = new FakeAIProvider(self::calls($second), self::calls($other));
        $agent = $this->agent($chatId, $provider);
        $result = $agent->decide((string) $first->getCallId(), false);

        $this->assertSame(ConcreteCmsAgent::AWAITING_REPLY, $result['reply'], 'the other tool waits for its card');
        $sent = self::sentResults($provider);
        // Scenario 41's first run: after the Deny the model tried create_page_draft for the same rename. The Deny itself
        // says not to make the change another way.
        $this->assertStringContainsString('with this tool or another', $sent[$first->getCallId()] ?? '', 'the Deny says not to try another way');
        $this->assertStringContainsString('Ask the user what they want instead', $sent[$first->getCallId()]);
        $this->assertStringContainsString('The user declined update_page', $sent[$second->getCallId()] ?? '', 'refused, not carded');
        $this->assertStringContainsString('Ask the user what they want instead', $sent[$second->getCallId()]);
        $statuses = $this->statuses($chatId);
        $this->assertSame(ApprovalService::DENIED, $statuses[$first->getCallId()]);
        $this->assertArrayNotHasKey((string) $second->getCallId(), $statuses, 'no card for the repeat');
        $this->assertSame(ApprovalService::PENDING, $statuses[$other->getCallId()]);
        CacheLocal::flush();
        $this->assertSame((string) $page->getCollectionName(), (string) Page::getByID($cID, 'RECENT')->getCollectionName(), 'nothing renamed');

        // The next turn starts afresh: update_page gets a card again.
        $this->agent($chatId, new FakeAIProvider(new AssistantMessage('Left it.')))->decide((string) $other->getCallId(), false);
        $next = self::call('update_page', ['pageID' => $cID, 'name' => 'Next turn']);
        $this->agent($chatId, new FakeAIProvider(self::calls($next)))->handleUserMessage('Now rename it to Next turn.');
        $this->assertSame(ApprovalService::PENDING, $this->statuses($chatId)[$next->getCallId()] ?? null, 'a new turn asks again');
    }

    public function testAFailedCallIsNotRepeatedAndAfterOneAlternativeTheToolStops(): void
    {
        $chatId = $this->newChat();
        ThrowawayPages::actAs('superuser');
        $failing = self::call('get_page', ['pageID' => 999999991]);
        $same = self::call('get_page', ['pageID' => 999999991]);
        $alternative = self::call('get_page', ['pageID' => 999999992]);
        $third = self::call('get_page', ['pageID' => 999999993]);
        $provider = new FakeAIProvider(
            self::calls($failing),
            self::calls($same),
            self::calls($alternative),
            self::calls($third),
            new AssistantMessage('I could not find that page.')
        );

        $reply = $this->agent($chatId, $provider)->handleUserMessage('Show me page 999999991.');

        $this->assertSame('I could not find that page.', $reply);
        $sent = self::sentResults($provider);
        $this->assertStringNotContainsString('already failed', $sent[$failing->getCallId()], 'the first attempt runs');
        $this->assertStringContainsString('already failed with these same inputs', $sent[$same->getCallId()]);
        $this->assertStringNotContainsString('already failed', $sent[$alternative->getCallId()], 'one alternative runs');
        $this->assertStringContainsString('has failed twice in this request', $sent[$third->getCallId()]);
        $this->assertStringContainsString('tell the user what failed', $sent[$third->getCallId()]);
    }
}
