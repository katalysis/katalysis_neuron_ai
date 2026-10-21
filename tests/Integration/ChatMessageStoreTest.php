<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Package\PackageService;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Chat\ChatMessageStore;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\PageContextTag;
use Katalysis\NeuronAi\Security\InjectionNotice;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

/**
 * P2.1 spike, plan section 5 and evidence 6: Neuron 4's message store on the existing KatalysisNeuronAiChats table.
 *
 * New chats are throwaway rows (deleted in tearDown()). The chats already in the dev database are only read: each
 * row's checksum is the same after the test as before, and continuing one runs on an in-memory copy (12.1,
 * condition 5). The model is Neuron's FakeAIProvider: no network, no API key.
 */
final class ChatMessageStoreTest extends ConcreteTestCase
{
    /** @var int[] */
    private array $chatIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // PHPUnit boots without starting packages, so map this package's Chat entity (as AgentPageContextTest does).
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function tearDown(): void
    {
        foreach ($this->chatIds as $id) {
            $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $this->chatIds = [];
        $this->em()->clear();
        Request::getInstance()->setCustomRequestUser(-1);
    }

    // ---- new chats ---------------------------------------------------------------------------------

    public function testANewChatIsStoredAndAFreshAgentContinuesIt(): void
    {
        $chatId = $this->newChat();
        $first = new FakeAIProvider(new AssistantMessage('First answer.'));
        $this->agent($chatId, $first)->setRuntimePageContext(['id' => 1, 'path' => '/neuron-test/headings'])->handleUserMessage('First question');

        // A second agent, as a second request would build it.
        $this->em()->clear();
        $second = new FakeAIProvider(new AssistantMessage('Second answer.'));
        $this->agent($chatId, $second)->handleUserMessage('Second question');

        $sent = $second->getRecorded()[0]->messages;
        $this->assertSame(['user', 'assistant', 'user'], array_map(static fn (Message $m): string => $m->getRole(), $sent), 'the second request carries the first turn');
        $this->assertStringContainsString('First question', $sent[0]->getContent());
        $this->assertSame('First answer.', $sent[1]->getContent());

        $row = $this->row($chatId);
        $stored = json_decode($row['chatHistory'], true);
        $this->assertCount(4, $stored);
        $this->assertSame(2, (int) $row['userMessageCount']);
        $this->assertSame('First question', $row['firstMessage'], 'the chat list shows what the person typed, without the page tag');
        $this->assertSame('Second question', $row['lastMessage']);
        $this->assertStringContainsString(PageContextTag::apply('First question', ['id' => 1, 'path' => '/neuron-test/headings']), $stored[0]['content'][0]['content'], 'the model was sent the tag, and it is stored');
        $this->assertStringNotContainsString('[Page', PageContextTag::stripFromHistoryJson($row['chatHistory']), 'the panel gets the history without the tag');
    }

    public function testToolCallsAndResultsAreStoredAsAPair(): void
    {
        $chatId = $this->newChat();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('get_current_page_context', 'call_1', [])]),
            new AssistantMessage('You are on a page.'),
        );
        $this->actAs('superuser');

        $this->agent($chatId, $provider)->handleUserMessage('Where am I?');

        $types = array_map(static fn (array $m): string => $m['role'] . (isset($m['type']) ? ':' . $m['type'] : ''), json_decode($this->row($chatId)['chatHistory'], true));
        $this->assertSame(['user', 'assistant:tool_call', 'user:tool_call_result', 'assistant'], $types);
    }

    public function testTheInjectionNoticeIsStoredOnTheFinalReplyAndShownAfterAReload(): void
    {
        $chatId = $this->newChat();
        $store = $this->store();
        $thread = ChatMessageStore::threadFor($chatId);
        $store->append($thread, new \NeuronAI\Chat\Messages\UserMessage('Read the page'));
        $store->append($thread, new AssistantMessage('Done.'));

        $this->assertTrue($store->flagLastAssistantMessage($thread, InjectionNotice::META_KEY, InjectionNotice::SUSPECTED, [
            InjectionNotice::META_SOURCE_KEY => InjectionNotice::SOURCE_PAGE,
        ]));

        $this->em()->clear();
        $reloaded = $this->store()->loadActive($thread);
        $this->assertSame(InjectionNotice::SUSPECTED, end($reloaded)->getMetadata(InjectionNotice::META_KEY));
        $annotated = json_decode(InjectionNotice::annotateHistoryJson($this->row($chatId)['chatHistory']), true);
        $this->assertSame(InjectionNotice::text(InjectionNotice::SUSPECTED, InjectionNotice::SOURCE_PAGE), $annotated[1]['notice'] ?? null);
        $this->assertArrayNotHasKey('notice', $annotated[0]);
    }

    public function testAToolCallMessageIsNeverFlagged(): void
    {
        $chatId = $this->newChat();
        $store = $this->store();
        $thread = ChatMessageStore::threadFor($chatId);
        $store->append($thread, new \NeuronAI\Chat\Messages\UserMessage('Read the page'));
        $store->append($thread, new ToolCallMessage(null, [new ToolCall('get_page', 'call_1', [])]));

        $this->assertFalse($store->flagLastAssistantMessage($thread, InjectionNotice::META_KEY, InjectionNotice::SUSPECTED));
    }

    public function testArchivedMessagesLeaveTheContextButStayInTheTranscript(): void
    {
        $chatId = $this->newChat();
        $store = $this->store();
        $thread = ChatMessageStore::threadFor($chatId);
        foreach (['one', 'two'] as $n) {
            $store->append($thread, new \NeuronAI\Chat\Messages\UserMessage("Question {$n}"));
            $store->append($thread, new AssistantMessage("Answer {$n}"));
        }

        $store->archive($thread, 2);

        $this->assertSame(['Question two', 'Answer two'], array_map(static fn (Message $m): string => $m->getContent(), $store->loadActive($thread)));
        $this->assertCount(4, $store->loadAll($thread));
    }

    public function testLoadingWritesNothing(): void
    {
        $chatId = $this->newChat();
        $store = $this->store();
        $thread = ChatMessageStore::threadFor($chatId);
        $store->append($thread, new \NeuronAI\Chat\Messages\UserMessage('Hello'));
        $before = $this->row($chatId);

        $this->em()->clear();
        $this->store()->loadActive($thread);
        $this->store()->loadAll($thread);
        $this->em()->flush();

        $this->assertSame($before, $this->row($chatId));
    }

    // ---- chats already in the dev database (read only) ----------------------------------------------

    public function testEveryStoredV3ChatLoadsFieldByFieldAndIsNotRewritten(): void
    {
        $rows = $this->db()->fetchAllAssociative('SELECT * FROM KatalysisNeuronAiChats WHERE chatHistory IS NOT NULL AND chatHistory <> "" ORDER BY id');
        if ($rows === []) {
            $this->markTestSkipped('No stored chats in this database.');
        }
        $checksums = $this->checksums();

        foreach ($rows as $row) {
            $raw = json_decode($row['chatHistory'], true);
            $loaded = $this->store()->loadActive(ChatMessageStore::threadFor((int) $row['id']));

            $this->assertCount(count($raw), $loaded, "chat {$row['id']}: message count");
            foreach ($raw as $i => $entry) {
                $message = $loaded[$i];
                $label = "chat {$row['id']} message {$i}";
                $this->assertSame($entry['role'], $message->getRole(), "{$label}: role");
                $this->assertSame($entry['__id'] ?? null, $message->getId(), "{$label}: id");
                $this->assertSame($this->text($entry), (string) $message->getContent(), "{$label}: text");
                if (($entry['type'] ?? null) === 'tool_call') {
                    $this->assertInstanceOf(ToolCallMessage::class, $message, $label);
                    $this->assertSame(array_column($entry['tools'], 'name'), array_map(static fn (ToolCall $c): string => $c->getName(), $message->getToolCalls()), "{$label}: tool names");
                    $this->assertSame(array_column($entry['tools'], 'inputs'), array_map(static fn (ToolCall $c): array => $c->getInputs(), $message->getToolCalls()), "{$label}: tool inputs");
                }
                if (($entry['type'] ?? null) === 'tool_call_result') {
                    $this->assertInstanceOf(ToolResultMessage::class, $message, $label);
                    $this->assertSame(array_column($entry['tools'], 'result'), array_map(static fn (ToolCall $c): string => (string) $c->getResult(), $message->getToolCalls()), "{$label}: tool results");
                }
                if (isset($entry[InjectionNotice::META_KEY])) {
                    $this->assertSame($entry[InjectionNotice::META_KEY], $message->getMetadata(InjectionNotice::META_KEY), "{$label}: v3 flat notice metadata");
                }
            }
        }

        $this->em()->flush();
        $this->assertSame($checksums, $this->checksums(), 'no stored chat may be rewritten');
    }

    public function testEveryStoredV3ChatContinuesOnAnInMemoryCopy(): void
    {
        $rows = $this->db()->fetchAllAssociative('SELECT id FROM KatalysisNeuronAiChats WHERE chatHistory IS NOT NULL AND chatHistory <> "" ORDER BY id');
        if ($rows === []) {
            $this->markTestSkipped('No stored chats in this database.');
        }
        $checksums = $this->checksums();

        foreach ($rows as $row) {
            $old = $this->store()->loadActive(ChatMessageStore::threadFor((int) $row['id']));
            $copy = new InMemoryMessageStore();
            $thread = 'copy-of-chat-' . $row['id'];
            foreach ($old as $message) {
                $copy->append($thread, $message);
            }
            $provider = new FakeAIProvider(new AssistantMessage('Continued.'));
            $agent = (new ConcreteCmsAgent())->setThreadId($thread)->setMessageStore($copy)->setAiProvider($provider);

            $this->assertSame('Continued.', $agent->handleUserMessage('One more thing.'), "chat {$row['id']}");
            $this->assertCount(count($old) + 1, $provider->getRecorded()[0]->messages, "chat {$row['id']}: the old turns were sent with the new question");
            $this->assertCount(count($old) + 2, $copy->loadAll($thread));
        }

        $this->em()->flush();
        $this->assertSame($checksums, $this->checksums(), 'no stored chat may be rewritten');
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function agent(int $chatId, FakeAIProvider $provider): ConcreteCmsAgent
    {
        return (new ConcreteCmsAgent())->setChatId($chatId)->setAiProvider($provider);
    }

    private function store(): ChatMessageStore
    {
        return new ChatMessageStore($this->em());
    }

    private function newChat(): int
    {
        $chat = new Chat();
        $chat->setSessionId('neuron-test-p21-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(0);
        $this->em()->persist($chat);
        $this->em()->flush();
        $this->chatIds[] = (int) $chat->getId();

        return (int) $chat->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $chatId): array
    {
        return $this->db()->fetchAssociative('SELECT * FROM KatalysisNeuronAiChats WHERE id = ?', [$chatId]);
    }

    /**
     * @return array<int, string> chat ID => sha1 of the whole row
     */
    private function checksums(): array
    {
        $out = [];
        foreach ($this->db()->fetchAllAssociative('SELECT * FROM KatalysisNeuronAiChats ORDER BY id') as $row) {
            ksort($row);
            $out[(int) $row['id']] = sha1((string) json_encode($row));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function text(array $entry): string
    {
        $content = $entry['content'] ?? '';
        if (is_string($content)) {
            return $content;
        }

        return implode(' ', array_map(static fn (array $b): string => (string) ($b['content'] ?? ''), array_filter($content, static fn ($b): bool => is_array($b) && ($b['type'] ?? null) === 'text')));
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info);
        Request::getInstance()->setCustomRequestUser($info);
    }

    private function em(): EntityManagerInterface
    {
        return $this->app()->make(EntityManagerInterface::class);
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
