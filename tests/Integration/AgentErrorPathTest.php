<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Security\InjectionNotice;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Chat\ChatMessageStore;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\Event;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P2.6 (plans/P2.6.md §4, §8.2): a failed turn is stored as it was on Neuron 3, the user's message and the fixed error
 * reply, whatever point the turn failed at. Neuron 4 itself stores nothing for a turn that fails on its first provider
 * call, and only the user's message for one that fails after a tool ran. Real turns of the agent with FakeAIProvider
 * as the model: no AI provider call, no API key.
 */
final class AgentErrorPathTest extends ConcreteTestCase
{
    private const ERROR_REPLY = 'I ran into an error processing that request. Please try again.';

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
        $page = Page::getByPath('/neuron-test/injection');
        if (!$page || $page->isError()) {
            $this->markTestSkipped('The dev site has no /neuron-test/injection fixture. Run documentation/fixtures/seed_test_content.php first.');
        }
        Request::getInstance()->setCustomRequestUser($this->app()->make(UserInfoRepository::class)->getByID(USER_SUPER_ID));
    }

    protected function tearDown(): void
    {
        foreach ($this->chatIds as $id) {
            $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $this->chatIds = [];
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function newChat(): int
    {
        $chat = new Chat();
        $chat->setSessionId('neuron-test-p26-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(USER_SUPER_ID);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        return (int) $chat->getId();
    }

    /**
     * A provider that gives its queued responses, then fails on call number $failOn (1-based).
     */
    private static function failingProvider(int $failOn, Message ...$responses): FakeAIProvider
    {
        return new class ($failOn, ...$responses) extends FakeAIProvider {
            public function __construct(private readonly int $failOn, Message ...$responses)
            {
                parent::__construct(...$responses);
            }

            public function chat(Message ...$messages): ProviderResponse
            {
                if ($this->getCallCount() + 1 >= $this->failOn) {
                    throw new \RuntimeException('The provider went away.');
                }

                return parent::chat(...$messages);
            }
        };
    }

    private static function readPage(string $path): ToolCallMessage
    {
        return new ToolCallMessage(null, [new ToolCall('get_page_content', 'call_' . bin2hex(random_bytes(3)), ['pageID' => (int) Page::getByPath($path)->getCollectionID()])]);
    }

    private function turn(int $chatId, FakeAIProvider $provider, string $message): string
    {
        return (new ConcreteCmsAgent())->setChatId($chatId)->setAiProvider($provider)->handleUserMessage($message);
    }

    /**
     * @return list<array{string, string, ?string}> role (with the type of a tool row), text, and the notice kind of each stored row
     */
    private function stored(int $chatId): array
    {
        $rows = $this->db()->fetchAllAssociative('SELECT role, type, content, meta FROM KatalysisNeuronAiChatMessages WHERE chatId = ? ORDER BY id', [$chatId]);

        return array_map(static function (array $row): array {
            $content = json_decode((string) $row['content'], true);
            $meta = json_decode((string) $row['meta'], true);

            return [
                $row['role'] . ($row['type'] !== null ? ':' . $row['type'] : ''),
                is_array($content) ? implode('', array_column($content, 'content')) : (string) $content,
                $meta['__meta'][InjectionNotice::META_KEY] ?? null,
            ];
        }, $rows);
    }

    public function testAFailureOnTheFirstCallStoresTheMessageAndTheErrorReply(): void
    {
        $chatId = $this->newChat();

        $this->assertSame(self::ERROR_REPLY, $this->turn($chatId, self::failingProvider(1), 'Hello'));

        $this->assertSame([['user', 'Hello', null], ['assistant', self::ERROR_REPLY, null]], $this->stored($chatId));
    }

    public function testAFailureAfterAToolRanStoresTheMessageOnceAndTheErrorReply(): void
    {
        $chatId = $this->newChat();

        $this->assertSame(self::ERROR_REPLY, $this->turn($chatId, self::failingProvider(2, self::readPage('/neuron-test/headings')), 'Read it.'));

        $this->assertSame([['user', 'Read it.', null], ['assistant', self::ERROR_REPLY, null]], $this->stored($chatId));
    }

    public function testATurnThatReadFlaggedContentBeforeFailingStoresTheNoticeOnTheErrorReply(): void
    {
        $chatId = $this->newChat();

        $this->turn($chatId, self::failingProvider(2, self::readPage('/neuron-test/injection')), 'Summarise it.');

        $this->assertSame([['user', 'Summarise it.', null], ['assistant', self::ERROR_REPLY, InjectionNotice::SUSPECTED]], $this->stored($chatId));
    }

    public function testFailedTurnsInARowKeepTheChatValidAndTheNextTurnWorks(): void
    {
        $chatId = $this->newChat();

        $this->turn($chatId, self::failingProvider(1), 'One');
        $this->turn($chatId, self::failingProvider(2, self::readPage('/neuron-test/headings')), 'Two');
        $provider = new FakeAIProvider(new AssistantMessage('Three answered.'));
        $this->assertSame('Three answered.', $this->turn($chatId, $provider, 'Three'));

        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_map(
            static fn (Message $m): string => $m->getRole(),
            $provider->getRecorded()[0]->messages
        ));
        $this->assertSame([
            ['user', 'One', null], ['assistant', self::ERROR_REPLY, null],
            ['user', 'Two', null], ['assistant', self::ERROR_REPLY, null],
            ['user', 'Three', null], ['assistant', 'Three answered.', null],
        ], $this->stored($chatId));
    }

    public function testAnAgentWithNoChatStillAnswersWithTheErrorReply(): void
    {
        // Neuron 4's chat() refuses to run without a thread; nothing is stored, nothing is thrown.
        $agent = (new ConcreteCmsAgent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Unreachable.')));

        $this->assertSame(self::ERROR_REPLY, $agent->handleUserMessage('Hello'));
    }

    // ---- BF-9: a chat never gets permanently stuck (plans/BF-9.md §3, §6) -------------------------------------------

    /**
     * A middleware that throws after Neuron's ToolNode has run for the $failOn-th time in the turn: a tool round that
     * fails after its tools ran (as the scanner's middleware could).
     */
    private static function failingToolRound(int $failOn): AgentMiddleware
    {
        return new class ($failOn) extends AgentMiddleware {
            private int $runs = 0;

            public function __construct(private readonly int $failOn)
            {
            }

            protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state, AgentResources $resources): void
            {
                if (++$this->runs >= $this->failOn) {
                    throw new \RuntimeException('The tool round went wrong.');
                }
            }
        };
    }

    /**
     * The stored rows as they are, without the load-time repair, plus the next turn's message, through Neuron's own
     * check: a failed turn must leave an order Neuron accepts by itself.
     */
    private function assertStoredOrderIsSendableWithoutRepair(int $chatId): void
    {
        $rows = (new ChatMessageStore($this->db()))->loadAll(ChatMessageStore::threadFor($chatId));
        (new HistoryTrimmer())->trim([...$rows, new UserMessage('Next question.')], PHP_INT_MAX);
        $this->addToAssertionCount(1);
    }

    public function testAFailureAfterTwoToolRoundsIsClosedWithTheErrorReplyAndTheNextTurnWorks(): void
    {
        // The case BF-5's investigation reproduced: Neuron has committed the first tool pair when the third call fails.
        $chatId = $this->newChat();
        $headings = '/neuron-test/headings';

        $this->assertSame(self::ERROR_REPLY, $this->turn($chatId, self::failingProvider(3, self::readPage($headings), self::readPage($headings)), 'Read it twice.'));

        $stored = $this->stored($chatId);
        $this->assertSame(['user', 'assistant:tool_call', 'user:tool_call_result', 'assistant'], array_column($stored, 0));
        $this->assertSame(self::ERROR_REPLY, $stored[3][1]);
        $callRow = json_decode((string) $this->db()->fetchOne("SELECT meta FROM KatalysisNeuronAiChatMessages WHERE chatId = ? AND type = 'tool_call'", [$chatId]), true);
        $this->assertNotNull($callRow['tools'][0]['result'] ?? null, 'a stored call from a normal round carries its result (plans/BF-9.md §2)');
        $this->assertStoredOrderIsSendableWithoutRepair($chatId);

        $provider = new FakeAIProvider(new AssistantMessage('Answered.'));
        $this->assertSame('Answered.', $this->turn($chatId, $provider, 'Try again.'));
        $provider->assertCallCount(1);
        $sent = $provider->getRecorded()[0]->messages;
        (new HistoryTrimmer())->trim($sent, PHP_INT_MAX);
        foreach ($sent as $message) {
            $this->assertStringNotContainsString('Recovered', (string) $message->getContent(), 'nothing needed repairing');
        }
    }

    /**
     * @return array<string, array{?int, ?int, int}> provider call that fails, tool round that fails, tool rounds asked for
     */
    public static function failurePoints(): array
    {
        return [
            'the first provider call' => [1, null, 0],
            'the second call, after one tool round' => [2, null, 1],
            'the third call, after two tool rounds' => [3, null, 2],
            'the fourth call, after three tool rounds' => [4, null, 3],
            'tool round 1' => [null, 1, 1],
            'tool round 2' => [null, 2, 2],
        ];
    }

    #[DataProvider('failurePoints')]
    public function testEveryFailurePointLeavesAChatTheNextTurnCanUse(?int $failingCall, ?int $failingRound, int $rounds): void
    {
        $chatId = $this->newChat();
        $responses = [];
        for ($i = 0; $i < $rounds; $i++) {
            $responses[] = self::readPage('/neuron-test/headings');
        }
        $responses[] = new AssistantMessage('Done.');
        $provider = $failingCall !== null ? self::failingProvider($failingCall, ...$responses) : new FakeAIProvider(...$responses);

        $agent = (new ConcreteCmsAgent())->setChatId($chatId)->setAiProvider($provider);
        if ($failingRound !== null) {
            $agent->addMiddleware(ToolNode::class, self::failingToolRound($failingRound));
        }

        $this->assertSame(self::ERROR_REPLY, $agent->handleUserMessage('Read it.'));
        $stored = $this->stored($chatId);
        $this->assertSame(['assistant', self::ERROR_REPLY], array_slice(end($stored), 0, 2), 'the failed turn ends with the error reply');
        $this->assertStoredOrderIsSendableWithoutRepair($chatId);

        $next = new FakeAIProvider(new AssistantMessage('Next answered.'));
        $this->assertSame('Next answered.', $this->turn($chatId, $next, 'Next.'));
        $next->assertCallCount(1);
    }
}
