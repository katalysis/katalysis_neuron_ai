<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Chat\ChatMessageStore;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P2.5 (plans/P2.5.md §3–§6): chat history on Neuron 4, one row per message in KatalysisNeuronAiChatMessages, linked to
 * the Chat row. Neuron's MessageStoreInterface contract, the summary columns, the format version, the thread IDs it
 * accepts, and the repair of two user messages (or two assistant replies) in a row, in memory only.
 *
 * Throwaway chat rows, deleted in tearDown(); their messages go with them (the foreign key cascades). Nothing here
 * calls the AI provider.
 */
final class ChatMessageStoreTest extends ConcreteTestCase
{
    /** @var int[] */
    private array $chatIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function tearDown(): void
    {
        foreach ($this->chatIds as $id) {
            $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $this->chatIds = [];
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function store(): ChatMessageStore
    {
        return new ChatMessageStore($this->db());
    }

    private function newThread(): string
    {
        $chat = new Chat();
        $chat->setSessionId('neuron-test-p25-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(0);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        return ChatMessageStore::threadFor((int) $chat->getId());
    }

    private function chatId(string $thread): int
    {
        return (int) substr($thread, strlen('chat-'));
    }

    /**
     * @param Message[] $messages
     */
    private function appendAll(string $thread, array $messages): void
    {
        foreach ($messages as $message) {
            $this->store()->append($thread, $message);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $thread): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT * FROM KatalysisNeuronAiChatMessages WHERE chatId = ? ORDER BY id',
            [$this->chatId($thread)]
        );
    }

    /**
     * Everything stored for the chat: every message row, every column, and the chat's own row.
     */
    private function checksum(string $thread): string
    {
        $header = $this->db()->fetchAssociative('SELECT * FROM KatalysisNeuronAiChats WHERE id = ?', [$this->chatId($thread)]);

        return md5((string) json_encode([$header, $this->rows($thread)]));
    }

    /**
     * @param Message[] $messages
     * @return string[]
     */
    private static function texts(array $messages): array
    {
        return array_map(static fn (Message $m): string => (string) $m->getContent(), $messages);
    }

    // ---- Neuron's store contract ------------------------------------------------------------------------------

    public function testMessagesComeBackInTheOrderTheyWereAppendedAndAnIdIsStoredOnce(): void
    {
        $thread = $this->newThread();
        $question = new UserMessage('First question.');
        $this->appendAll($thread, [$question, new AssistantMessage('First reply.'), new UserMessage('Second question.')]);
        $this->store()->append($thread, $question);

        // loadAll(): loadActive() adds a placeholder reply after the final question (BF-9), which is not what this checks.
        $this->assertSame(['First question.', 'First reply.', 'Second question.'], self::texts($this->store()->loadAll($thread)));
        $this->assertCount(3, $this->rows($thread), 'appending the same message ID again stores nothing');
        $this->assertSame($question->getId(), $this->store()->loadActive($thread)[0]->getId());
    }

    public function testMessagesAreOrderedByRowIdNeverByTimestamp(): void
    {
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.'), new AssistantMessage('Two.'), new UserMessage('Three.'), new AssistantMessage('Four.')]);
        $ids = array_column($this->rows($thread), 'id');

        // Two inserts with the same timestamp.
        $this->db()->executeStatement('UPDATE KatalysisNeuronAiChatMessages SET createdAt = ? WHERE id IN (?, ?)', ['2026-10-21 12:00:00', $ids[0], $ids[1]]);
        // A later row with an earlier timestamp.
        $this->db()->executeStatement('UPDATE KatalysisNeuronAiChatMessages SET createdAt = ? WHERE id IN (?, ?)', ['2026-10-21 11:00:00', $ids[2], $ids[3]]);

        $this->assertSame(['One.', 'Two.', 'Three.', 'Four.'], self::texts($this->store()->loadActive($thread)));
        $this->assertSame(['One.', 'Two.', 'Three.', 'Four.'], self::texts($this->store()->loadAll($thread)));
    }

    public function testArchivedMessagesLeaveTheActiveContextButStayInTheTranscript(): void
    {
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.'), new AssistantMessage('Two.'), new UserMessage('Three.'), new AssistantMessage('Four.')]);

        $this->store()->archive($thread, 2);

        $this->assertSame(['Three.', 'Four.'], self::texts($this->store()->loadActive($thread)));
        $this->assertSame(['One.', 'Two.', 'Three.', 'Four.'], self::texts($this->store()->loadAll($thread)));
        $this->assertSame(2, count(array_filter(array_column($this->rows($thread), 'archivedAt'))));
    }

    public function testLoadAllPagesBackwardsFromACursor(): void
    {
        $thread = $this->newThread();
        $messages = [new UserMessage('One.'), new AssistantMessage('Two.'), new UserMessage('Three.'), new AssistantMessage('Four.')];
        $this->appendAll($thread, $messages);

        $this->assertSame(['Three.', 'Four.'], self::texts($this->store()->loadAll($thread, 2)));
        $this->assertSame(['One.', 'Two.'], self::texts($this->store()->loadAll($thread, 2, $messages[2]->getId())));
        $this->assertSame(['One.'], self::texts($this->store()->loadAll($thread, null, $messages[1]->getId())));
        $this->assertSame([], $this->store()->loadAll($thread, 2, 'msg_unknown'));
        $this->assertSame([], $this->store()->loadAll($thread, 0));
    }

    public function testClearRemovesTheThreadsMessagesAndResetsTheSummary(): void
    {
        $thread = $this->newThread();
        $other = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.'), new AssistantMessage('Two.')]);
        $this->appendAll($other, [new UserMessage('Elsewhere.')]);

        $this->store()->clear($thread);

        $this->assertSame([], $this->store()->loadAll($thread));
        $this->assertCount(1, $this->store()->loadAll($other), 'another thread is not touched');
        $header = $this->db()->fetchAssociative('SELECT firstMessage, lastMessage, userMessageCount FROM KatalysisNeuronAiChats WHERE id = ?', [$this->chatId($thread)]);
        $this->assertSame(['firstMessage' => '', 'lastMessage' => '', 'userMessageCount' => 0], [
            'firstMessage' => $header['firstMessage'],
            'lastMessage' => $header['lastMessage'],
            'userMessageCount' => (int) $header['userMessageCount'],
        ]);
    }

    public function testEachKindOfMessageComesBackFieldByField(): void
    {
        $thread = $this->newThread();
        $call = new ToolCall('get_page', 'call_1', ['pageId' => 12, 'path' => '/neuron-test'], 'Gets a page');
        $failed = new ToolCall('update_page', 'call_2', ['pageId' => 12]);
        $reply = (new AssistantMessage('Done.'))->setUsage(new Usage(120, 30));
        $this->appendAll($thread, [
            new UserMessage("[Current page for this message: Headings, /neuron-test/headings, ID 7. Earlier messages may refer to other pages.]\n\nRead it."),
            new ToolCallMessage(null, [$call, $failed]),
            new ToolResultMessage([
                (clone $call)->setResult('{"ok":true,"data":{"name":"Headings"}}'),
                (clone $failed)->setResult(ToolOutput::error('{"ok":false,"error":"No."}')),
            ]),
            $reply,
        ]);

        [$user, $toolCall, $toolResult, $assistant] = $this->store()->loadActive($thread);

        $this->assertInstanceOf(UserMessage::class, $user);
        $this->assertStringStartsWith('[Current page for this message: Headings', (string) $user->getContent());
        $this->assertStringEndsWith("\n\nRead it.", (string) $user->getContent());

        $this->assertInstanceOf(ToolCallMessage::class, $toolCall);
        $this->assertSame(['get_page', 'update_page'], array_map(static fn (ToolCall $c): string => $c->getName(), $toolCall->getToolCalls()));
        $this->assertSame('call_1', $toolCall->getToolCalls()[0]->getCallId());
        $this->assertSame(['pageId' => 12, 'path' => '/neuron-test'], $toolCall->getToolCalls()[0]->getInputs());

        $this->assertInstanceOf(ToolResultMessage::class, $toolResult);
        $this->assertSame('{"ok":true,"data":{"name":"Headings"}}', $toolResult->getToolCalls()[0]->getResult());
        $error = $toolResult->getToolCalls()[1]->getResult();
        $this->assertInstanceOf(ToolOutput::class, $error);
        $this->assertTrue($error->isError());

        $this->assertInstanceOf(AssistantMessage::class, $assistant);
        $this->assertNotInstanceOf(ToolCallMessage::class, $assistant);
        $this->assertSame('Done.', $assistant->getContent());
        $this->assertSame(120, $assistant->getUsage()?->inputTokens);
        $this->assertSame($reply->getId(), $assistant->getId());

        $this->assertSame([null, 'tool_call', 'tool_call_result', null], array_column($this->rows($thread), 'type'));
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column($this->rows($thread), 'role'));
    }

    public function testTheSummaryColumnsShowWhatThePersonTyped(): void
    {
        $thread = $this->newThread();
        $call = new ToolCall('get_page', 'call_1', []);
        $this->appendAll($thread, [
            new UserMessage("[Current page for this message: Headings, /neuron-test/headings, ID 7. Earlier messages may refer to other pages.]\n\nWhat is on this page?"),
            new ToolCallMessage(null, [$call]),
            new ToolResultMessage([(clone $call)->setResult('{"ok":true}')]),
            new AssistantMessage('Headings.'),
            new UserMessage('And the next one?'),
            new AssistantMessage('Columns.'),
        ]);

        $header = $this->db()->fetchAssociative('SELECT firstMessage, lastMessage, userMessageCount FROM KatalysisNeuronAiChats WHERE id = ?', [$this->chatId($thread)]);
        $this->assertSame('What is on this page?', $header['firstMessage']);
        $this->assertSame('And the next one?', $header['lastMessage']);
        $this->assertSame(2, (int) $header['userMessageCount'], "the person's messages only; a tool result is not counted (plan §11.2)");
    }

    public function testRowsAreWrittenWithTheFormatVersion(): void
    {
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.'), new AssistantMessage('Two.')]);

        $this->assertSame([ChatMessageStore::FORMAT_VERSION, ChatMessageStore::FORMAT_VERSION], array_map('intval', array_column($this->rows($thread), 'formatVersion')));
        $this->assertSame(1, ChatMessageStore::FORMAT_VERSION);
    }

    public function testARowFromANewerFormatIsRefusedNotMisread(): void
    {
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.')]);
        $this->db()->executeStatement('UPDATE KatalysisNeuronAiChatMessages SET formatVersion = 99 WHERE chatId = ?', [$this->chatId($thread)]);

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('format version 99');
        $this->store()->loadActive($thread);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notAChatThread(): array
    {
        return [
            'letters' => ['chat-abc'],
            'no number' => ['chat-'],
            'trailing space' => ['chat-1 '],
            'negative' => ['chat--1'],
            'another prefix' => ['other-1'],
            'session-like' => ['p19-1a2b3c'],
            'no such chat' => ['chat-999999999'],
        ];
    }

    #[DataProvider('notAChatThread')]
    public function testAThreadThatIsNotAChatIsRefused(string $thread): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->store()->loadActive($thread);
    }

    public function testThreadForNamesTheChat(): void
    {
        $this->assertSame('chat-42', ChatMessageStore::threadFor(42));
    }

    public function testDeletingTheChatDeletesItsMessages(): void
    {
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.'), new AssistantMessage('Two.')]);

        $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$this->chatId($thread)]);

        $this->assertSame([], $this->rows($thread));
    }

    public function testTheTranscriptJsonIsTheListThePanelReads(): void
    {
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.'), new AssistantMessage('Two.')]);
        $this->store()->archive($thread, 1);

        $list = json_decode($this->store()->transcriptJson($thread), true);

        $this->assertCount(2, $list, 'archived messages are part of the transcript');
        $this->assertSame(['user', 'assistant'], array_column($list, 'role'));
        $this->assertSame('One.', $list[0]['content'][0]['content']);
        $this->assertSame('[]', $this->store()->transcriptJson($this->newThread()));
    }

    // ---- The repair (plan §6) --------------------------------------------------------------------------------

    public function testTwoUserMessagesInARowAreRepairedInMemoryOnly(): void
    {
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('First question.'), new UserMessage('Second question.'), new AssistantMessage('An answer.')]);
        $before = $this->checksum($thread);

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_map(static fn (Message $m): string => $m->getRole(), $messages));
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
        $this->assertStringContainsString('Recovered', (string) $messages[1]->getContent());
        $this->assertNull($messages[1]->getUsage());
        $this->assertSame('msg_recovered_' . $messages[2]->getId(), $messages[1]->getId());
        (new HistoryTrimmer())->trim($messages, PHP_INT_MAX);
        $this->assertSame($before, $this->checksum($thread), 'loading never writes');
        $this->assertCount(3, $this->store()->loadAll($thread), 'the transcript shows what was actually stored');
    }

    public function testTwoAssistantRepliesInARowAreRepaired(): void
    {
        // Neuron 4 rejects them (plan §11.1), after the provider call.
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('A question.'), new AssistantMessage('One reply.'), new AssistantMessage('Another reply.')]);

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_map(static fn (Message $m): string => $m->getRole(), $messages));
        $this->assertInstanceOf(UserMessage::class, $messages[2]);
        $this->assertStringContainsString('Recovered', (string) $messages[2]->getContent());
        (new HistoryTrimmer())->trim($messages, PHP_INT_MAX);
    }

    public function testTwoRequestsOnOneChatAtOnceAreRepaired(): void
    {
        // Two tabs: each request read [..., A] and committed its own question and reply, interleaved.
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('Tab one.'), new UserMessage('Tab two.'), new AssistantMessage('Reply one.'), new AssistantMessage('Reply two.')]);

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(
            ['Tab one.', 'Recovered', 'Tab two.', 'Reply one.', 'Recovered', 'Reply two.'],
            array_map(static fn (Message $m): string => str_contains((string) $m->getContent(), 'Recovered') ? 'Recovered' : (string) $m->getContent(), $messages)
        );
        (new HistoryTrimmer())->trim($messages, PHP_INT_MAX);
    }

    public function testTheRepairIsTheSameOnEveryLoad(): void
    {
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.'), new UserMessage('Two.'), new AssistantMessage('Three.')]);

        $first = array_map(static fn (Message $m): string => $m->getId(), $this->store()->loadActive($thread));
        $second = array_map(static fn (Message $m): string => $m->getId(), $this->store()->loadActive($thread));

        $this->assertSame($first, $second);
    }

    public function testArchiveCountsThePlaceholdersAsNeuronDoes(): void
    {
        // Neuron archives "the oldest N" of what loadActive() returned, placeholders included (ChatHistory::addMessage()).
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.'), new UserMessage('Two.'), new AssistantMessage('Three.'), new UserMessage('Four.'), new AssistantMessage('Five.')]);
        $view = $this->store()->loadActive($thread);
        $this->assertCount(6, $view);

        // Neuron's cut before "Two." drops "One." and the placeholder: 2 of its messages, 1 stored row.
        $this->store()->archive($thread, 2);

        $this->assertSame(['Two.', 'Three.', 'Four.', 'Five.'], self::texts($this->store()->loadActive($thread)));
        $this->assertSame(1, count(array_filter(array_column($this->rows($thread), 'archivedAt'))));
    }

    public function testAThreadWaitingForApprovalIsLeftAlone(): void
    {
        // P3.6: a thread waiting for approval ends with the tool call, written before Neuron suspends.
        $thread = $this->newThread();
        $call = new ToolCall('update_page', 'call_1', ['pageId' => 12]);
        $call->setApprovalState(ApprovalState::Pending);
        $this->appendAll($thread, [new UserMessage('Rename the page.'), new ToolCallMessage(null, [$call])]);

        $messages = $this->store()->loadActive($thread);

        $this->assertCount(2, $messages);
        $this->assertInstanceOf(ToolCallMessage::class, $messages[1]);
        $this->assertSame(ApprovalState::Pending, $messages[1]->getToolCalls()[0]->getApprovalState());
    }

    // ---- BF-9: a chat never gets permanently stuck (plans/BF-9.md §4) ----------------------------------------------

    /**
     * A tool call as Neuron's pair-commit stores it: the call carries its result (the same ToolCall objects the tool
     * wrote into).
     */
    private static function ranCall(string $result = '{"ok":true,"data":{"title":"Headings"}}'): ToolCallMessage
    {
        $call = new ToolCall('get_page_content', 'call_' . bin2hex(random_bytes(3)), ['pageID' => 1]);
        $call->setResult($result);

        return new ToolCallMessage(null, [$call]);
    }

    private static function resultOf(ToolCallMessage $call): ToolResultMessage
    {
        return new ToolResultMessage($call->getToolCalls());
    }

    /**
     * What Neuron checks when the next turn's message is committed: the view plus a new user message.
     *
     * @param Message[] $messages
     */
    private function assertSendable(array $messages): void
    {
        (new HistoryTrimmer())->trim([...$messages, new UserMessage('Next question.')], PHP_INT_MAX);
        $this->addToAssertionCount(1);
    }

    /**
     * @param Message[] $messages
     * @return string[] each message's kind: user, assistant, tool_call, tool_result, or recovered (a placeholder)
     */
    private static function kinds(array $messages): array
    {
        return array_map(static fn (Message $m): string => match (true) {
            $m instanceof ToolCallMessage => 'tool_call',
            $m instanceof ToolResultMessage => str_starts_with($m->getId(), 'msg_recovered_') ? 'recovered_result' : 'tool_result',
            str_starts_with($m->getId(), 'msg_recovered_') => 'recovered',
            default => $m->getRole(),
        }, $messages);
    }

    private function logMark(): int
    {
        return (int) $this->db()->fetchOne('SELECT COALESCE(MAX(logID), 0) FROM Logs');
    }

    /**
     * @return string[]
     */
    private function loggedSince(int $mark): array
    {
        return $this->db()->fetchFirstColumn('SELECT message FROM Logs WHERE logID > ? ORDER BY logID', [$mark]);
    }

    public function testAChatEndingInAToolResultGetsAReplyPlaceholderAtTheEnd(): void
    {
        // What a turn that failed after its second tool round leaves (C3 failed, or the process timed out).
        $thread = $this->newThread();
        $call = self::ranCall();
        $this->appendAll($thread, [new UserMessage('Read it twice.'), $call, self::resultOf($call)]);
        $before = $this->checksum($thread);

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(['user', 'tool_call', 'tool_result', 'recovered'], self::kinds($messages));
        $this->assertInstanceOf(AssistantMessage::class, $messages[3]);
        $this->assertStringContainsString('Recovered', (string) $messages[3]->getContent());
        $this->assertSendable($messages);
        $this->assertSame(
            array_map(static fn (Message $m): string => $m->getId(), $messages),
            array_map(static fn (Message $m): string => $m->getId(), $this->store()->loadActive($thread)),
            'the same placeholder on every load'
        );
        $this->assertSame($before, $this->checksum($thread), 'loading never writes');
    }

    public function testAChatEndingInTheUsersMessageGetsAReplyPlaceholderAtTheEnd(): void
    {
        // A turn that timed out after its first provider call, before its first tool pair was saved.
        $thread = $this->newThread();
        $this->appendAll($thread, [new UserMessage('One.'), new AssistantMessage('Reply one.'), new UserMessage('Two.')]);

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(['user', 'assistant', 'user', 'recovered'], self::kinds($messages));
        $this->assertSame('msg_recovered_end_' . $messages[2]->getId(), $messages[3]->getId());
        $this->assertSendable($messages);
    }

    public function testAToolResultFollowedByAUserMessageGetsAReplyPlaceholderBetween(): void
    {
        // The two-tab shape: another request committed its question and reply after this one's tool result.
        $thread = $this->newThread();
        $call = self::ranCall();
        $this->appendAll($thread, [new UserMessage('One.'), $call, self::resultOf($call), new UserMessage('Two.'), new AssistantMessage('Reply two.')]);

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(['user', 'tool_call', 'tool_result', 'recovered', 'user', 'assistant'], self::kinds($messages));
        $this->assertSame('msg_recovered_' . $messages[4]->getId(), $messages[3]->getId());
        $this->assertSendable($messages);
    }

    public function testAToolCallWithItsResultsButNoResultMessageHasItsResultRebuilt(): void
    {
        // The database failed between the call's row and the result's row.
        $thread = $this->newThread();
        $call = self::ranCall('{"ok":true,"data":{"title":"Rebuilt from the call"}}');
        $this->appendAll($thread, [new UserMessage('Read it.'), $call]);
        $before = $this->checksum($thread);

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(['user', 'tool_call', 'recovered_result', 'recovered'], self::kinds($messages));
        $this->assertSame('msg_recovered_' . $messages[1]->getId(), $messages[2]->getId());
        $rebuilt = $messages[2]->getToolCalls()[0];
        $this->assertSame('{"ok":true,"data":{"title":"Rebuilt from the call"}}', $rebuilt->getResult(), 'the result the tool returned');
        $this->assertSame($call->getToolCalls()[0]->getCallId(), $rebuilt->getCallId());
        $this->assertNotSame($messages[1]->getToolCalls()[0], $rebuilt, 'a copy: the loaded call is not changed');
        $this->assertSendable($messages);
        $this->assertSame($before, $this->checksum($thread), 'loading never writes');
    }

    public function testAToolCallWithItsResultsFollowedByMoreMessagesHasItsResultRebuilt(): void
    {
        $thread = $this->newThread();
        $call = self::ranCall();
        $this->appendAll($thread, [new UserMessage('One.'), $call, new UserMessage('Two.'), new AssistantMessage('Reply two.')]);

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(['user', 'tool_call', 'recovered_result', 'recovered', 'user', 'assistant'], self::kinds($messages));
        $this->assertSendable($messages);
    }

    public function testAWaitingToolCallFollowedByAUserMessageIsLeftAlone(): void
    {
        // A call with no result is waiting for approval (P3.6): never rebuilt, never cut. The agent refuses the next
        // turn before the provider instead (AgentRepairBeforeProviderTest).
        $thread = $this->newThread();
        $call = new ToolCall('update_page', 'call_1', ['pageId' => 12]);
        $call->setApprovalState(ApprovalState::Pending);
        $this->appendAll($thread, [new UserMessage('Rename the page.'), new ToolCallMessage(null, [$call]), new UserMessage('Hello?'), new AssistantMessage('Hi.')]);

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(['user', 'tool_call', 'user', 'assistant'], self::kinds($messages));
        $this->assertFalse($messages[1]->getToolCalls()[0]->hasResult());
    }

    public function testAnOrderNoRuleCoversStartsTheViewAtTheNextUserMessageItCanSend(): void
    {
        // A tool result with no call before it: nothing can be rebuilt for it.
        $thread = $this->newThread();
        $orphan = self::resultOf(self::ranCall());
        $this->appendAll($thread, [new UserMessage('One.'), $orphan, new AssistantMessage('Reply one.'), new UserMessage('Two.'), new AssistantMessage('Reply two.')]);
        $before = $this->checksum($thread);
        $mark = $this->logMark();

        $messages = $this->store()->loadActive($thread);

        $this->assertSame(['Two.', 'Reply two.'], self::texts($messages));
        $this->assertSendable($messages);
        $logged = implode("\n", $this->loggedSince($mark));
        $this->assertStringContainsString('chat ' . $this->chatId($thread), $logged);
        $this->assertStringContainsString('3 messages left out', $logged);
        $this->assertSame($before, $this->checksum($thread), 'loading never writes');
        $this->assertCount(5, $this->store()->loadAll($thread), 'the transcript still shows every stored message');
    }

    public function testArchiveCountsTheToolResultPlaceholdersAsNeuronDoes(): void
    {
        $thread = $this->newThread();
        $call = self::ranCall();
        $this->appendAll($thread, [new UserMessage('One.'), $call, self::resultOf($call), new UserMessage('Two.'), new AssistantMessage('Three.')]);
        $this->assertCount(6, $this->store()->loadActive($thread));

        // Neuron's cut before "Two." drops 4 of its messages: 3 stored rows and the placeholder.
        $this->store()->archive($thread, 4);

        $this->assertSame(['Two.', 'Three.'], self::texts($this->store()->loadActive($thread)));
        $this->assertSame(3, count(array_filter(array_column($this->rows($thread), 'archivedAt'))));
    }
}
