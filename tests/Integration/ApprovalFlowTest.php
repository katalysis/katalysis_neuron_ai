<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\Chat\ChatMessageStore;
use Katalysis\NeuronAi\Chat\TurnChanges;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ApproveAll;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\GlobalAreaFixture;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * P3.6-1 (plans/P3.6-1.md): a gated write waits for the user's Approve or Deny, across requests. Real turns with
 * FakeAIProvider calling the real tools on throwaway pages: no AI provider call. Each request gets a new agent bound to
 * the chat, as over HTTP, so the resume always reads the paused run back from the database.
 */
final class ApprovalFlowTest extends ConcreteTestCase
{
    /** Inputs Neuron accepts for each gated tool. */
    private const VALID_INPUTS = [
        'update_page' => ['pageID' => 1, 'name' => 'x'],
        'set_page_attribute_value' => ['pageID' => 1, 'handle' => 'meta_title', 'value' => 'x'],
        'create_page' => ['parentID' => 1, 'name' => 'x'],
    ];

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
            // A test that ends paused leaves a run: end it, then the chat (its approval rows cascade).
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
        $chat->setSessionId('neuron-test-p361-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(USER_SUPER_ID);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        return (int) $chat->getId();
    }

    /** A new agent on the chat, as each request makes one. */
    private function agent(int $chatId, FakeAIProvider $provider): ConcreteCmsAgent
    {
        $this->app()->make(EntityManagerInterface::class)->clear();
        CacheLocal::flush();

        return (new ConcreteCmsAgent())->setChatId($chatId)->setAiProvider($provider);
    }

    private static function calls(ToolCall ...$calls): ToolCallMessage
    {
        return new ToolCallMessage(null, $calls);
    }

    private static function call(string $tool, array $inputs): ToolCall
    {
        return new ToolCall($tool, 'call_' . bin2hex(random_bytes(4)), $inputs);
    }

    private static function newest(int $cID): Page
    {
        CacheLocal::flush();

        return Page::getByID($cID, 'RECENT');
    }

    /** @return array<string, string> status by call ID */
    private function statuses(int $chatId): array
    {
        return array_column($this->db()->fetchAllAssociative('SELECT callId, status FROM KatalysisNeuronAiApprovals WHERE chatId = ? ORDER BY id', [$chatId]), 'status', 'callId');
    }

    private function runRows(int $chatId): int
    {
        return (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM ' . ConcreteCmsAgent::WORKFLOW_TABLE . ' WHERE `partition` = ?',
            [bin2hex(ChatMessageStore::threadFor($chatId))]
        );
    }

    /** The tool results the provider was sent, as text. */
    private static function sentResults(FakeAIProvider $provider): string
    {
        $text = '';
        foreach ($provider->getRecorded() as $request) {
            foreach ($request->messages as $message) {
                if ($message instanceof ToolResultMessage) {
                    foreach ($message->getToolCalls() as $call) {
                        $text .= (string) $call->getResult() . "\n";
                    }
                }
            }
        }

        return $text;
    }

    // ---- The policy ------------------------------------------------------------------------------------------

    public function testEveryGatedToolTheAgentOffersAlwaysAsksAndNeverSaysFalse(): void
    {
        $this->pages->create('Approval policy');
        $agent = (new ConcreteCmsAgent())->setChatId($this->newChat());
        $method = new \ReflectionMethod($agent, 'tools');

        $found = [];
        // Built twice: the policy is put on the tools of every segment, not once.
        foreach ([1, 2] as $pass) {
            foreach ($method->invoke($agent) as $item) {
                foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                    if (!$tool instanceof ToolInterface) {
                        continue;
                    }
                    $decision = $tool->requiresApproval();
                    if (!ApprovalService::isGated($tool->getName())) {
                        $this->assertFalse($decision, "{$tool->getName()} is not gated");
                        continue;
                    }
                    $found[$tool->getName()] = true;
                    // P3.6-2: every write is gated; the page tools keep their own reasons.
                    $this->assertSame(ApprovalService::reason($tool->getName()), $decision, "pass {$pass}: {$tool->getName()}");
                    if (!isset(self::VALID_INPUTS[$tool->getName()])) {
                        continue;
                    }
                    // With a call's inputs bound and on a clone, as Neuron asks on every pass and on resume. (Neuron
                    // never gates a call whose inputs it rejected: such a call is refused without running.)
                    $clone = clone $tool;
                    $clone->setInputs(self::VALID_INPUTS[$tool->getName()]);
                    $this->assertSame(ApprovalService::POLICIES[$tool->getName()], $clone->requiresApproval(), "{$tool->getName()} with inputs");
                }
            }
        }
        $this->assertSame(array_keys(ApprovalService::POLICIES), array_values(array_intersect(array_keys(ApprovalService::POLICIES), array_keys($found))), 'the superuser is offered every gated tool');
    }

    // ---- Pause, approve, deny ---------------------------------------------------------------------------------

    public function testAGatedCallWaitsAndApproveInALaterRequestRunsIt(): void
    {
        $page = $this->pages->create('Approval approve');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $call = self::call('update_page', ['pageID' => $cID, 'description' => 'Approved description']);
        $versionBefore = (int) self::newest($cID)->getVersionID();

        $first = $this->agent($chatId, new FakeAIProvider(self::calls($call)));
        $this->assertSame(ConcreteCmsAgent::AWAITING_REPLY, $first->handleUserMessage('Set the description.'));
        $this->assertTrue($first->isAwaitingApproval());
        $this->assertSame((int) $versionBefore, (int) self::newest($cID)->getVersionID(), 'nothing is written before Approve');
        $this->assertSame([$call->getCallId() => ApprovalService::PENDING], $this->statuses($chatId));
        $this->assertGreaterThan(0, $this->runRows($chatId), 'the paused run is kept in the database');

        $cards = $first->getApprovalCards();
        $this->assertCount(1, $cards);
        $this->assertSame('Change page', $cards[0]['action']);
        $this->assertStringContainsString('Approval approve', $cards[0]['target']);
        // BF-22: the field is labelled so it can't be taken for the meta description.
        $this->assertStringContainsString('Page description (not the meta description): (empty) → "Approved description"', implode("\n", $cards[0]['details']));
        $this->assertStringContainsString('unpublished', $cards[0]['note']);

        // The next request: a new agent, the run read back from the database.
        $provider = new FakeAIProvider(new AssistantMessage('Done.'));
        $result = $this->agent($chatId, $provider)->decide((string) $call->getCallId(), true);
        $this->assertSame(['recorded' => true, 'reply' => 'Done.'], $result);
        $this->assertSame('Approved description', (string) self::newest($cID)->getCollectionDescription());
        $this->assertSame([$call->getCallId() => ApprovalService::APPROVED], $this->statuses($chatId));
        $this->assertSame(0, $this->runRows($chatId), 'a completed run leaves nothing behind');
        $this->assertStringNotContainsString('TOOL NOT EXECUTED', self::sentResults($provider));
    }

    public function testDenyWritesNothingAndTellsTheModel(): void
    {
        $page = $this->pages->create('Approval deny');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $call = self::call('update_page', ['pageID' => $cID, 'name' => 'Denied name']);
        $versionBefore = (int) self::newest($cID)->getVersionID();

        $this->agent($chatId, new FakeAIProvider(self::calls($call)))->handleUserMessage('Rename it.');
        $provider = new FakeAIProvider(new AssistantMessage('OK, I left it.'));
        $agent = $this->agent($chatId, $provider);
        $result = $agent->decide((string) $call->getCallId(), false, 'Keep the old name');

        $this->assertSame(['recorded' => true, 'reply' => 'OK, I left it.'], $result);
        $this->assertSame($versionBefore, (int) self::newest($cID)->getVersionID(), 'no draft was made');
        $this->assertSame([$call->getCallId() => ApprovalService::DENIED], $this->statuses($chatId));
        $this->assertStringContainsString('TOOL NOT EXECUTED', self::sentResults($provider));
        $this->assertStringContainsString('Keep the old name', self::sentResults($provider));
        $this->assertStringContainsString('Nothing was changed. Not done: update_page', (string) $agent->getTurnChangesLine());
    }

    public function testAPageChangedAfterThePreviewIsNotWritten(): void
    {
        $page = $this->pages->create('Approval stale');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $call = self::call('update_page', ['pageID' => $cID, 'description' => 'From the assistant']);
        $this->agent($chatId, new FakeAIProvider(self::calls($call)))->handleUserMessage('Set the description.');

        // Someone edits the page before Approve: a new version.
        $edited = self::newest($cID)->getVersionToModify();
        $edited->update(['cDescription' => 'From a person']);
        $versionAfterEdit = (int) self::newest($cID)->getVersionID();

        $provider = new FakeAIProvider(new AssistantMessage('The page changed.'));
        $result = $this->agent($chatId, $provider)->decide((string) $call->getCallId(), true);

        $this->assertTrue($result['recorded']);
        $this->assertSame('From a person', (string) self::newest($cID)->getCollectionDescription());
        $this->assertSame($versionAfterEdit, (int) self::newest($cID)->getVersionID());
        $this->assertSame([$call->getCallId() => ApprovalService::STALE], $this->statuses($chatId));
        $this->assertStringContainsString(ApprovalService::STALE_REASON, self::sentResults($provider));
    }

    /** P3.6a: a version of a global area's stack started after its card makes the approval stale; nothing is written. */
    public function testAStackChangedAfterTheGlobalAreaCardIsNotWritten(): void
    {
        $fixture = new GlobalAreaFixture($this->db());
        ThrowawayPages::actAs('superuser');
        try {
            [$handle, $stackID, $bID] = $fixture->globalArea();
            $page = $fixture->page([$handle]);
            $chatId = $this->newChat();
            $call = self::call('update_block_in_page_area', [
                'pageID' => (int) $page->getCollectionID(), 'areaHandle' => $handle, 'blockID' => $bID,
                'value' => ['content' => '<p>Original global content</p><p>From the assistant</p>'],
            ]);
            $agent = $this->agent($chatId, new FakeAIProvider(self::calls($call)));
            $agent->handleUserMessage('Change the global area.');
            $this->assertStringContainsString('the global area "' . $handle . '"', $agent->getApprovalCards()[0]['action']);

            // Someone edits the global area before Approve: a new stack version.
            self::newest($stackID)->getVersionToModify();
            $stackVersions = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$stackID]);

            $this->agent($chatId, new FakeAIProvider(new AssistantMessage('It changed.')))->decide((string) $call->getCallId(), true);

            $this->assertSame([$call->getCallId() => ApprovalService::STALE], $this->statuses($chatId));
            $this->assertSame($stackVersions, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$stackID]), 'nothing was written');
        } finally {
            $fixture->restore();
        }
    }

    public function testTwoGatedCallsOnOnePageBothApprovedAreBothAppliedAndNeitherIsStale(): void
    {
        $page = $this->pages->create('Approval batch');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $update = self::call('update_page', ['pageID' => $cID, 'description' => 'Batch description']);
        $attribute = self::call('set_page_attribute_value', ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Batch Meta Title']);

        $this->agent($chatId, new FakeAIProvider(self::calls($update, $attribute)))->handleUserMessage('Set both.');
        $this->assertCount(2, $this->statuses($chatId));

        // The first Approve: nothing runs yet, the other call still waits.
        $waiting = $this->agent($chatId, new FakeAIProvider())->decide((string) $update->getCallId(), true);
        $this->assertSame(['recorded' => true, 'reply' => null], $waiting);
        $this->assertSame('', (string) self::newest($cID)->getCollectionDescription(), 'no call runs until every call in the batch is decided');

        // The second: both fingerprints are checked before either write, so the first write can't make the second stale.
        $result = $this->agent($chatId, new FakeAIProvider(new AssistantMessage('Both done.')))->decide((string) $attribute->getCallId(), true);
        $this->assertSame(['recorded' => true, 'reply' => 'Both done.'], $result);
        $newest = self::newest($cID);
        $this->assertSame('Batch description', (string) $newest->getCollectionDescription());
        $this->assertSame('Batch Meta Title', (string) $newest->getAttribute('meta_title'));
        $this->assertSame(
            [$update->getCallId() => ApprovalService::APPROVED, $attribute->getCallId() => ApprovalService::APPROVED],
            $this->statuses($chatId)
        );
    }

    public function testTheFirstDecisionWinsAcrossTwoTabs(): void
    {
        $page = $this->pages->create('Approval two tabs');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $call = self::call('update_page', ['pageID' => $cID, 'description' => 'Tab one']);
        $this->agent($chatId, new FakeAIProvider(self::calls($call)))->handleUserMessage('Set it.');

        $tabTwo = new FakeAIProvider(new AssistantMessage('Unreachable.'));
        $this->assertSame(['recorded' => true, 'reply' => 'Done.'], $this->agent($chatId, new FakeAIProvider(new AssistantMessage('Done.')))->decide((string) $call->getCallId(), true));
        $this->assertSame(['recorded' => false, 'reply' => null], $this->agent($chatId, $tabTwo)->decide((string) $call->getCallId(), false));
        $tabTwo->assertCallCount(0);
        $this->assertSame([$call->getCallId() => ApprovalService::APPROVED], $this->statuses($chatId));
        $this->assertSame('Tab one', (string) self::newest($cID)->getCollectionDescription());
    }

    // ---- A new message while a call waits -----------------------------------------------------------------------

    public function testANewMessageRejectsTheWaitingCallAndTheTurnGoesOn(): void
    {
        $page = $this->pages->create('Approval new message');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $call = self::call('update_page', ['pageID' => $cID, 'description' => 'Never approved']);
        $versionBefore = (int) self::newest($cID)->getVersionID();
        $this->agent($chatId, new FakeAIProvider(self::calls($call)))->handleUserMessage('Set it.');

        $provider = new FakeAIProvider(new AssistantMessage('Understood, not done.'), new AssistantMessage('Hello again.'));
        $agent = $this->agent($chatId, $provider);
        $this->assertSame('Hello again.', $agent->handleUserMessage('Actually, just say hello.'));

        $this->assertSame($versionBefore, (int) self::newest($cID)->getVersionID());
        $this->assertSame([$call->getCallId() => ApprovalService::NOT_APPROVED], $this->statuses($chatId));
        $this->assertStringContainsString(ApprovalService::NEW_MESSAGE_REASON, self::sentResults($provider));
        $this->assertSame([], $agent->getApprovalCards());
        $settled = $agent->getSettledApprovalCards();
        $this->assertCount(1, $settled);
        $this->assertSame(ApprovalService::STATUS_TEXT[ApprovalService::NOT_APPROVED], $settled[0]['statusText']);
        $this->assertSame(0, $this->runRows($chatId));

        // The model's answer to the rejection is stored, not shown; the new turn's reply is.
        $shown = array_map(
            static fn (array $m): string => (string) ($m['content'][0]['content'] ?? $m['content'][0]['text'] ?? ''),
            json_decode((new ChatMessageStore($this->db()))->conversationJson(ChatMessageStore::threadFor($chatId)), true)
        );
        $this->assertNotContains('Understood, not done.', $shown);
        $this->assertContains('Hello again.', $shown);
    }

    // ---- The previews -----------------------------------------------------------------------------------------

    public function testTheAttributeAndCreatePagePreviewsSayWhatWillHappen(): void
    {
        $page = $this->pages->create('Approval previews');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $attribute = self::call('set_page_attribute_value', ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Preview Title']);
        $create = self::call('create_page', ['parentID' => $cID, 'name' => 'Preview Child']);

        $agent = $this->agent($chatId, new FakeAIProvider(self::calls($attribute, $create)));
        $agent->handleUserMessage('Do both.');
        $cards = array_column($agent->getApprovalCards(), null, 'tool');

        $this->assertSame('Set Meta Title', $cards['set_page_attribute_value']['action']);
        $this->assertSame(['Meta Title: (empty) → "Preview Title"'], $cards['set_page_attribute_value']['details']);
        $this->assertSame('Create page', $cards['create_page']['action']);
        $this->assertStringContainsString('"Preview Child" under Approval previews', $cards['create_page']['target']);
        $this->assertStringContainsString("visitors won't see it", $cards['create_page']['note']);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM Pages WHERE cParentID = ?', [$cID]), 'a preview creates nothing');
    }

    // ---- The "Changed" line -----------------------------------------------------------------------------------

    public function testTheChangedLineIsStoredOnTheReply(): void
    {
        $page = $this->pages->create('Approval changed line');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $call = self::call('update_page', ['pageID' => $cID, 'description' => 'Line description']);
        $this->agent($chatId, new FakeAIProvider(self::calls($call)))->handleUserMessage('Set it.');
        $agent = $this->agent($chatId, new FakeAIProvider(new AssistantMessage('Done.')));
        $agent->decide((string) $call->getCallId(), true);

        $line = (string) $agent->getTurnChangesLine();
        $this->assertStringStartsWith('Changed: Approval changed line', $line);
        $this->assertStringContainsString((string) $page->getCollectionPath(), $line);
        $this->assertMatchesRegularExpression('/unpublished version \d+/', $line);

        $history = json_decode(TurnChanges::annotateHistoryJson((new ChatMessageStore($this->db()))->conversationJson(ChatMessageStore::threadFor($chatId))), true);
        $last = end($history);
        $this->assertSame($line, $last['changes'] ?? null, 'a reload shows the same line');
    }

    public function testAFailedTurnStoresWhatItChangedBeforeTheError(): void
    {
        $page = $this->pages->create('Approval failed turn');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $html = trim((string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$b1]));
        $edit = self::calls(self::call('update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1,
            'value' => ['content' => (string) preg_replace('/^<p>(.*)<\/p>$/s', '<h2>$1</h2>', $html)],
            'changeMode' => 'formatting_only',
        ]));
        $provider = new class ($edit) extends FakeAIProvider {
            public function chat(Message ...$messages): ProviderResponse
            {
                if ($this->getCallCount() >= 1) {
                    throw new \RuntimeException('The provider went away.');
                }

                return parent::chat(...$messages);
            }
        };
        $chatId = $this->newChat();

        $this->assertSame(ConcreteCmsAgent::ERROR_REPLY, ApproveAll::turn($this->agent($chatId, $provider), 'Make it a heading.'));

        $history = json_decode(TurnChanges::annotateHistoryJson((new ChatMessageStore($this->db()))->conversationJson(ChatMessageStore::threadFor($chatId))), true);
        $last = end($history);
        $this->assertStringStartsWith('Before the error: changed Approval failed turn', (string) ($last['changes'] ?? ''));
        $this->assertSame(0, $this->runRows($chatId), 'the failed run is ended');
    }

    public function testAFailedApprovedBatchDoesNotStopTheNextTurn(): void
    {
        // P3.6-2 (B-ERR-5): the call was stored before the pause; when its turn fails after it ran, the next message
        // must still get an answer.
        $page = $this->pages->create('Approval failed batch');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $call = self::call('update_page', ['pageID' => $cID, 'description' => 'Written, then the provider failed']);
        $this->agent($chatId, new FakeAIProvider(self::calls($call)))->handleUserMessage('Set it.');

        $failing = new class () extends FakeAIProvider {
            public function chat(Message ...$messages): ProviderResponse
            {
                throw new \RuntimeException('The provider went away.');
            }
        };
        $this->assertSame(['recorded' => true, 'reply' => ConcreteCmsAgent::ERROR_REPLY], $this->agent($chatId, $failing)->decide((string) $call->getCallId(), true));
        $this->assertSame('Written, then the provider failed', (string) self::newest($cID)->getCollectionDescription());

        $this->assertSame('Still here.', $this->agent($chatId, new FakeAIProvider(new AssistantMessage('Still here.')))->handleUserMessage('Hello?'));
        $this->assertSame(0, $this->runRows($chatId));
    }

    public function testTwoApprovedUpdatesToOneBlockInOneResponseAreBothApplied(): void
    {
        // P3.6-2 (C): both calls name the block's live ID. The first copies it into the draft; the second follows the
        // copy instead of being refused as "not in the newest version".
        $page = $this->pages->create('Approval same block');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $chatId = $this->newChat();
        $first = self::call('update_block_in_page_area', ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => '<p>Block one original text. Added A.</p>']]);
        $second = self::call('update_block_in_page_area', ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b1, 'value' => ['content' => '<p>Block one original text. Added A. Added B.</p>']]);

        $this->agent($chatId, new FakeAIProvider(self::calls($first, $second)))->handleUserMessage('Add two sentences.');
        $this->agent($chatId, new FakeAIProvider())->decide((string) $first->getCallId(), true);
        $provider = new FakeAIProvider(new AssistantMessage('Both added.'));
        $this->assertSame(['recorded' => true, 'reply' => 'Both added.'], $this->agent($chatId, $provider)->decide((string) $second->getCallId(), true));

        $this->assertStringNotContainsString('Error executing tool', self::sentResults($provider), 'neither call was refused');
        [$copy] = $this->pages->blockIDs($cID);
        $this->assertNotSame($b1, $copy, 'changed in a draft copy');
        $this->assertSame('<p>Block one original text. Added A. Added B.</p>', trim((string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$copy])));
        $this->assertSame('<p>Block one original text.</p>', trim((string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$b1])), 'the live block is unchanged');
        $this->assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$cID]), 'one draft');
    }

    public function testACallItsOwnChecksWouldRefuseIsNotPutToTheUser(): void
    {
        // P3.6-2 (A): scenario 29's made-up ID. The block isn't on the page, so the call is refused at once, in the
        // tool's own words, and the turn goes on: no click, and the model can retry with the right ID.
        $page = $this->pages->create('Approval refused');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $call = self::call('delete_block_from_page_area', ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => 999999999]);
        $provider = new FakeAIProvider(self::calls($call), new AssistantMessage('That block is not there.'));

        $agent = $this->agent($chatId, $provider);
        $this->assertSame('That block is not there.', $agent->handleUserMessage('Delete it.'));
        $this->assertFalse($agent->isAwaitingApproval());

        $this->assertSame([$call->getCallId() => ApprovalService::REFUSED], $this->statuses($chatId));
        $results = self::sentResults($provider);
        $this->assertStringStartsWith('Error executing tool: Block 999999999 not found', trim($results), 'the tool\'s own words');
        $this->assertStringNotContainsString('TOOL NOT EXECUTED', $results);
        $cards = $agent->getSettledApprovalCards();
        $this->assertSame(ApprovalService::REFUSED, $cards[0]['status']);
        $this->assertStringStartsWith('Not asked: this would be refused: Block 999999999 not found', $cards[0]['statusText']);
        $this->assertStringContainsString('Not done: delete_block_from_page_area', (string) $agent->getTurnChangesLine());
        $this->assertSame(0, $this->runRows($chatId));
    }

    public function testInAMixedBatchOnlyTheCallThatCanSucceedIsPutToTheUser(): void
    {
        $page = $this->pages->create('Approval mixed');
        $cID = (int) $page->getCollectionID();
        $chatId = $this->newChat();
        $good = self::call('update_page', ['pageID' => $cID, 'description' => 'Mixed batch']);
        $bad = self::call('update_page', ['pageID' => 999999999, 'description' => 'Nowhere']);

        $agent = $this->agent($chatId, new FakeAIProvider(self::calls($good, $bad)));
        $this->assertSame(ConcreteCmsAgent::AWAITING_REPLY, $agent->handleUserMessage('Set both.'));
        $cards = array_column($agent->getApprovalCards(), 'status', 'callId');
        $this->assertSame([$good->getCallId() => ApprovalService::PENDING, $bad->getCallId() => ApprovalService::REFUSED], $cards);

        $provider = new FakeAIProvider(new AssistantMessage('One done.'));
        $this->assertSame(['recorded' => true, 'reply' => 'One done.'], $this->agent($chatId, $provider)->decide((string) $good->getCallId(), true));
        $this->assertSame('Mixed batch', (string) self::newest($cID)->getCollectionDescription());
        $this->assertStringContainsString('Error executing tool: Page with ID 999999999 not found', self::sentResults($provider));
    }
}
