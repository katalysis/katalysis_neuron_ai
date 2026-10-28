<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\PageContextTag;
use Katalysis\NeuronAi\Security\SecretRedactor;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;

/**
 * Neuron 4's message store on our tables (P2.5, plans/P2.5.md): one row per message in KatalysisNeuronAiChatMessages,
 * linked to the Chat row in KatalysisNeuronAiChats. It follows Neuron's own SQLMessageStore: the row ID orders the
 * thread (never a timestamp), the message ID makes append idempotent, archived messages keep their rows.
 *
 * Threads: a thread is one Chat row, named "chat-<id>". The thread ID is untrusted input; anything else, or a chat that
 * does not exist, is refused. Who may open which chat is the controller's check, not the store's.
 *
 * Format version: every row stores FORMAT_VERSION. 1 is Neuron 4.1's Message::jsonSerialize(), split into the content
 * blocks and the rest (meta). A row from a newer version is refused, never misread; an older one goes through
 * upgradeRow(), the one place a future format change is handled. Once real installs exist, a format change also adds a
 * one-off batch migration to the package's upgrade().
 *
 * The repair: Neuron 4 checks the order of a conversation only when a turn is committed, after a paid provider call, so
 * a stored order it refuses would fail every later turn (BF-9). loadActive() repairs it in memory, before Neuron builds
 * the request: loading never writes. Two plain user messages or two plain assistant replies in a row (two tabs, P2.5)
 * get a placeholder between them; a tool result with a user message (or nothing) after it gets a placeholder reply (a
 * turn that failed or timed out after a tool round); a tool call that carries its results but has no result message (a
 * write that failed between the two rows) gets its result message rebuilt from those results. Any order still refused
 * after that is sent from the earliest user message Neuron accepts (logged with how many messages were left out).
 * Placeholders are the same on every load (their IDs come from the messages around them), and archive() counts them as
 * Neuron does, so the stored rows and Neuron's view of them always agree. A tool call with a call that has no result is
 * waiting for approval or an external result (P3.6): it is never touched, wherever it is.
 *
 * Writes also keep the chat list's columns on the Chat row: the first and last message the person typed (page tag
 * stripped) and how many messages they sent.
 */
final class ChatMessageStore implements MessageStoreInterface
{
    public const FORMAT_VERSION = 1;

    private const TABLE = 'KatalysisNeuronAiChatMessages';
    private const CHATS = 'KatalysisNeuronAiChats';
    private const PREFIX = 'chat-';
    private const PLACEHOLDER_ID = 'msg_recovered_';
    /** In the ID of a placeholder at the end of the thread, which takes its ID from the message before it. */
    private const AT_END = 'end_';
    private const RECOVERED_REPLY = "[Recovered: the assistant's reply to this message was not saved]";
    private const RECOVERED_MESSAGE = '[Recovered: the message for this reply was not saved]';
    private const RECOVERED_TOOL_REPLY = "[Recovered: the assistant's reply after this tool result was not saved]";

    /**
     * The result message of each loaded tool call whose stored row holds every call's result, rebuilt from that row.
     * Neuron's deserializer drops the results from a tool call (a call record needs none), but the row keeps them.
     *
     * @var \WeakMap<ToolCallMessage, ToolResultMessage>
     */
    private \WeakMap $ranCalls;

    public function __construct(private readonly Connection $db)
    {
        $this->ranCalls = new \WeakMap();
    }

    public static function threadFor(int $chatId): string
    {
        return self::PREFIX . $chatId;
    }

    public function loadActive(string $threadId): array
    {
        $chatId = $this->chatId($threadId);
        $messages = $this->deserialize($this->rows('chatId = ? AND archivedAt IS NULL', [$chatId]));

        return $this->repair($messages, $chatId);
    }

    /**
     * loadActive() without its log lines, for a check that loads the same view again before Neuron does (the agent's
     * check before the provider, BF-9), so a repair is logged once per turn.
     *
     * @return list<Message>
     */
    public function loadActiveQuietly(string $threadId): array
    {
        $chatId = $this->chatId($threadId);

        return $this->repair($this->deserialize($this->rows('chatId = ? AND archivedAt IS NULL', [$chatId])), $chatId, false);
    }

    /**
     * Whether a message is one the repair made in memory (a placeholder or a rebuilt tool result): it has no row.
     */
    public static function isRecovered(Message $message): bool
    {
        return str_starts_with($message->getId(), self::PLACEHOLDER_ID);
    }

    /**
     * Whether a list of messages, followed by the next turn's user message, is an order Neuron accepts: its own check
     * (HistoryTrimmer::trim() validates the order; an unbounded window never trims). A list ending in a tool call that is
     * waiting (P3.6) is checked without the next message, which can't follow it.
     *
     * @param Message[] $messages
     */
    private function isSendable(array $messages): bool
    {
        $last = end($messages);
        $next = $last instanceof ToolCallMessage && $this->isWaiting($last) ? [] : [new UserMessage('')];

        try {
            (new HistoryTrimmer())->trim([...$messages, ...$next], PHP_INT_MAX);

            return true;
        } catch (ChatHistoryException) {
            return false;
        }
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        $chatId = $this->chatId($threadId);
        $conditions = 'chatId = ?';
        $parameters = [$chatId];

        if ($before !== null) {
            $cursor = $this->db->fetchOne('SELECT id FROM ' . self::TABLE . ' WHERE chatId = ? AND messageId = ?', [$chatId, $before]);
            if ($cursor === false) {
                return [];
            }
            $conditions .= ' AND id < ?';
            $parameters[] = (int) $cursor;
        }

        if ($limit !== null) {
            // The newest rows before the cursor, returned in insertion order.
            $ids = $this->db->fetchFirstColumn(
                'SELECT id FROM ' . self::TABLE . " WHERE {$conditions} ORDER BY id DESC LIMIT " . max(0, $limit),
                $parameters
            );
            if ($ids === []) {
                return [];
            }
            $conditions = 'id IN (' . implode(',', array_map('intval', $ids)) . ')';
            $parameters = [];
        }

        return $this->deserialize($this->rows($conditions, $parameters));
    }

    public function append(string $threadId, Message $message): void
    {
        $chatId = $this->chatId($threadId);
        $data = $message->jsonSerialize();
        $content = $data['content'] ?? null;
        unset($data['role'], $data['content'], $data['__id']);

        // The unique (chatId, messageId) index skips a message already stored, as SQLMessageStore does.
        $this->db->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (chatId, messageId, role, type, content, meta, formatVersion, createdAt)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE messageId = messageId',
            [
                $chatId,
                $message->getId(),
                $message->getRole(),
                is_string($data['type'] ?? null) ? $data['type'] : null,
                $content !== null ? json_encode($content, JSON_THROW_ON_ERROR) : null,
                json_encode($data, JSON_THROW_ON_ERROR),
                self::FORMAT_VERSION,
                date('Y-m-d H:i:s'),
            ]
        );

        $this->updateSummary($chatId);
    }

    public function archive(string $threadId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        // Neuron counts what loadActive() returned, placeholders included; they have no row to archive.
        $chatId = $this->chatId($threadId);
        $rows = $this->rows('chatId = ? AND archivedAt IS NULL', [$chatId]);
        $rowIds = array_column($rows, 'id', 'messageId');
        $ids = [];
        foreach (array_slice($this->repair($this->deserialize($rows), $chatId, false), 0, $count) as $message) {
            if (isset($rowIds[$message->getId()])) {
                $ids[] = (int) $rowIds[$message->getId()];
            }
        }
        if ($ids === []) {
            return;
        }

        $this->db->executeStatement(
            'UPDATE ' . self::TABLE . ' SET archivedAt = ? WHERE id IN (' . implode(',', $ids) . ')',
            [date('Y-m-d H:i:s')]
        );
    }

    public function clear(string $threadId): void
    {
        $chatId = $this->chatId($threadId);
        $this->db->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE chatId = ?', [$chatId]);

        $this->updateSummary($chatId);
    }

    /**
     * Puts metadata on the thread's last message, if that is a plain assistant reply (not a tool call). The injection
     * notice (P1.11) belongs to the turn's final reply. Not part of Neuron's store contract: called after a turn has
     * ended, never while one runs. Neuron does not send metadata to the provider.
     *
     * P3.6-1: a value may be a JSON-encodable array (the turn's "Changed" line, TurnChanges::META_KEY).
     *
     * @param string|array<string, mixed> $value
     * @param array<string, string|array<string, mixed>> $more further metadata values to put on the same message
     *
     * @return bool whether a message was flagged
     */
    public function flagLastAssistantMessage(string $threadId, string $key, string|array $value, array $more = []): bool
    {
        $chatId = $this->chatId($threadId);
        $last = $this->db->fetchAssociative('SELECT id, role, type, meta FROM ' . self::TABLE . ' WHERE chatId = ? ORDER BY id DESC LIMIT 1', [$chatId]);
        if ($last === false || $last['role'] !== 'assistant' || $last['type'] !== null) {
            return false;
        }

        $data = json_decode((string) $last['meta'], true);
        $data = is_array($data) ? $data : [];
        $meta = is_array($data['__meta'] ?? null) ? $data['__meta'] : [];
        $data['__meta'] = array_merge($meta, [$key => $value], $more);

        $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET meta = ? WHERE id = ?', [json_encode($data, JSON_THROW_ON_ERROR), (int) $last['id']]);

        return true;
    }

    /**
     * The whole stored chat, archived messages included, as a JSON list of messages: each message as
     * Message::jsonSerialize() writes it. No placeholders: it shows what was actually stored.
     */
    public function transcriptJson(string $threadId): string
    {
        return (string) json_encode(array_map(static fn (Message $m): array => $m->jsonSerialize(), $this->loadAll($threadId)), JSON_THROW_ON_ERROR);
    }

    /**
     * What the chat panel shows (BF-11): the transcript with only the person's messages and the final replies. The
     * model's notes on its tool calls and the tool results are left out, as in the live reply. The same rule as the
     * chat list's count: a tool result has the user role but isn't the person's. A reply flagged hidden (the model's
     * answer to calls a new message rejected, P3.6-1) is left out too.
     */
    public function conversationJson(string $threadId): string
    {
        $shown = array_filter(
            $this->loadAll($threadId),
            static fn (Message $m): bool => ($m instanceof UserMessage && !$m instanceof ToolResultMessage)
                || ($m instanceof AssistantMessage && !$m instanceof ToolCallMessage && !self::isHidden($m))
        );

        return (string) json_encode(array_values(array_map(static fn (Message $m): array => $m->jsonSerialize(), $shown)), JSON_THROW_ON_ERROR);
    }

    private static function isHidden(Message $message): bool
    {
        return ($message->jsonSerialize()['__meta'][\Katalysis\NeuronAi\ConcreteCmsAgent::HIDDEN_META_KEY] ?? null) === '1';
    }

    /**
     * The Chat row's ID for a thread ID, which must be "chat-<digits>" for an existing chat.
     */
    private function chatId(string $threadId): int
    {
        if (preg_match('/^' . self::PREFIX . '([1-9][0-9]{0,9})$/D', $threadId, $match) !== 1) {
            throw new ChatHistoryException("Unknown thread \"{$threadId}\": threads are named chat-<id>.");
        }
        $chatId = (int) $match[1];
        if ($this->db->fetchOne('SELECT id FROM ' . self::CHATS . ' WHERE id = ?', [$chatId]) === false) {
            throw new ChatHistoryException("No chat for thread \"{$threadId}\".");
        }

        return $chatId;
    }

    /**
     * @param array<int, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $conditions, array $parameters): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, messageId, role, content, meta, formatVersion FROM ' . self::TABLE . " WHERE {$conditions}",
            $parameters
        );
        // Sorted by row ID in PHP, as SQLMessageStore does: never by timestamp, and no filesort of the large columns.
        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Message>
     */
    private function deserialize(array $rows): array
    {
        $deserializer = new MessageDeserializer();

        return array_map(function (array $row) use ($deserializer): Message {
            $row = $this->upgradeRow($row);
            $data = json_decode((string) $row['meta'], true);

            $message = $deserializer->deserialize(array_merge(is_array($data) ? $data : [], [
                '__id' => $row['messageId'],
                'role' => $row['role'],
                'content' => $row['content'] !== null ? json_decode((string) $row['content'], true) : null,
            ]));

            $tools = is_array($data['tools'] ?? null) ? $data['tools'] : [];
            if ($message instanceof ToolCallMessage && $tools !== [] && !in_array(null, array_column($tools, 'result'), true)
                && count(array_column($tools, 'result')) === count($tools)) {
                $this->ranCalls[$message] = $deserializer->deserialize(['role' => 'user', 'type' => 'tool_call_result', 'tools' => $tools, 'content' => null]);
            }

            return $message;
        }, $rows);
    }

    /**
     * Brings a stored row to FORMAT_VERSION. Version 1 is the only one so far, so there is nothing to convert yet.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function upgradeRow(array $row): array
    {
        $version = (int) $row['formatVersion'];
        if ($version > self::FORMAT_VERSION) {
            throw new ChatHistoryException(sprintf(
                'Message %s is stored in format version %d; this code reads up to version %d.',
                $row['messageId'],
                $version,
                self::FORMAT_VERSION
            ));
        }

        return $row;
    }

    /**
     * Puts a placeholder between two plain user messages, or two plain assistant replies, in a row, and before a plain
     * assistant reply that opens the context (what a trim can leave after a repaired pair). Puts a placeholder reply
     * after a tool result that a user message, or nothing, follows. Rebuilds the result message of a tool call that
     * carries its results but has none. Then, if Neuron would still refuse the order, starts the view at the earliest
     * user message from which it accepts it (fallback()). A waiting tool call is never touched.
     *
     * @param list<Message> $messages
     * @return list<Message>
     */
    private function repair(array $messages, int $chatId, bool $log = true): array
    {
        $repaired = [];
        $previousRole = 'assistant';
        $positions = [];

        foreach ($messages as $index => $message) {
            $next = $messages[$index + 1] ?? null;

            if (self::isPlain($message) && $message->getRole() === $previousRole) {
                $placeholder = $previousRole === 'user'
                    ? new AssistantMessage(self::RECOVERED_REPLY)
                    : new UserMessage(self::RECOVERED_MESSAGE);
                $placeholder->setId(self::PLACEHOLDER_ID . $message->getId());
                $positions[] = count($repaired);
                $repaired[] = $placeholder;
            }
            $repaired[] = $message;
            $previousRole = self::isPlain($message) ? $message->getRole() : null;

            // A call committed without its result row: its row holds the results the tools returned.
            if ($message instanceof ToolCallMessage && !$this->isWaiting($message) && !$next instanceof ToolResultMessage) {
                $result = $this->ranCalls[$message];
                $result->setId(self::PLACEHOLDER_ID . $message->getId());
                $positions[] = count($repaired);
                $repaired[] = $result;
                $message = $result;
            }

            // The assistant speaks after a tool result: a user message (or the next turn's) can't come next.
            if ($message instanceof ToolResultMessage && ($next === null || (self::isPlain($next) && $next->getRole() === 'user'))) {
                $placeholder = new AssistantMessage(self::RECOVERED_TOOL_REPLY);
                $placeholder->setId(self::PLACEHOLDER_ID . ($next === null ? self::AT_END . $message->getId() : $next->getId()));
                $positions[] = count($repaired);
                $repaired[] = $placeholder;
                $previousRole = 'assistant';
            }
        }

        // A thread ending in the person's message (a turn that timed out before its reply was stored): the next turn's
        // message comes after it.
        $last = end($repaired);
        if ($last !== false && self::isPlain($last) && $last->getRole() === 'user') {
            $placeholder = new AssistantMessage(self::RECOVERED_REPLY);
            $placeholder->setId(self::PLACEHOLDER_ID . self::AT_END . $last->getId());
            $positions[] = count($repaired);
            $repaired[] = $placeholder;
        }

        if ($log && $positions !== []) {
            \Concrete\Core\Support\Facade\Log::notice(SecretRedactor::redact(sprintf(
                'Katalysis Neuron AI: chat %d has messages in an order the model refuses; placeholders added in memory at position %s (nothing stored).',
                $chatId,
                implode(', ', $positions)
            )));
        }

        return $this->fallback($repaired, $chatId, $log);
    }

    /**
     * The general rule (BF-9): an order Neuron refuses never fails every later turn. If the repaired view would still
     * be refused, the model's view starts at the earliest plain user message from which Neuron accepts it, or is empty.
     * The messages before it stay stored and in the transcript. A view holding a waiting tool call is left as it is: the
     * agent refuses the turn before the provider instead.
     *
     * @param list<Message> $messages
     * @return list<Message>
     */
    private function fallback(array $messages, int $chatId, bool $log): array
    {
        if ($this->isSendable($messages)) {
            return $messages;
        }
        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage && $this->isWaiting($message)) {
                return $messages;
            }
        }

        $start = count($messages);
        foreach ($messages as $index => $message) {
            if ($index > 0 && $message::class === UserMessage::class && $this->isSendable(array_slice($messages, $index))) {
                $start = $index;
                break;
            }
        }

        if ($log) {
            \Concrete\Core\Support\Facade\Log::notice(SecretRedactor::redact(sprintf(
                'Katalysis Neuron AI: chat %d still has messages in an order the model refuses after the repair; the model\'s view starts at position %d, %d messages left out (nothing stored or deleted).',
                $chatId,
                $start,
                $start
            )));
        }

        return array_slice($messages, $start);
    }

    private static function isPlain(Message $message): bool
    {
        return !$message instanceof ToolCallMessage && !$message instanceof ToolResultMessage;
    }

    /**
     * A tool call waiting for approval or an external result (P3.6): Neuron writes it before the call runs, so its row
     * has a call with no result. A call from a normal tool round is committed after it ran, and its row holds every
     * result. A tool call that didn't come from a row counts as waiting: it is never touched.
     */
    private function isWaiting(ToolCallMessage $message): bool
    {
        return !isset($this->ranCalls[$message]);
    }

    /**
     * The chat list's columns on the Chat row: the person's first and last message, without the page tag, and how many
     * they sent. Tool results have the user role but are not the person's, so they are not counted.
     */
    private function updateSummary(int $chatId): void
    {
        $contents = $this->db->fetchFirstColumn(
            'SELECT content FROM ' . self::TABLE . " WHERE chatId = ? AND role = 'user' AND type IS NULL ORDER BY id",
            [$chatId]
        );
        $first = $contents === [] ? '' : self::text($contents[0]);
        $last = $contents === [] ? '' : self::text($contents[count($contents) - 1]);

        $this->db->executeStatement(
            'UPDATE ' . self::CHATS . ' SET firstMessage = ?, lastMessage = ?, userMessageCount = ?, updatedDate = ? WHERE id = ?',
            [
                mb_substr(PageContextTag::strip($first), 0, 500),
                mb_substr(PageContextTag::strip($last), 0, 500),
                count($contents),
                date('Y-m-d H:i:s'),
                $chatId,
            ]
        );
    }

    /**
     * The text of a stored content column (a JSON list of content blocks).
     */
    private static function text(mixed $json): string
    {
        $blocks = json_decode((string) $json, true);
        $parts = [];
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['content'] ?? null)) {
                $parts[] = $block['content'];
            }
        }

        return trim(implode(' ', $parts));
    }
}
