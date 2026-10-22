<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\PageContextTag;
use Katalysis\NeuronAi\Security\SecretRedactor;
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
 * The repair: Neuron 4 refuses a conversation with two plain user messages, or two plain assistant replies, in a row,
 * but only when the turn is committed, after a paid provider call. Two requests on one chat at once (two tabs) can store
 * both shapes. loadActive() puts a placeholder between them, in memory only, before Neuron builds the request: loading
 * never writes. The placeholder is the same on every load (its ID comes from the message after it), and archive()
 * counts it as Neuron does, so the stored rows and Neuron's view of them always agree. A thread waiting for approval
 * (ending in a tool call) is left alone.
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
    private const RECOVERED_REPLY = "[Recovered: the assistant's reply to this message was not saved]";
    private const RECOVERED_MESSAGE = '[Recovered: the message for this reply was not saved]';

    public function __construct(private readonly Connection $db)
    {
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
     * @param array<string, string> $more further metadata values to put on the same message
     *
     * @return bool whether a message was flagged
     */
    public function flagLastAssistantMessage(string $threadId, string $key, string $value, array $more = []): bool
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
     * The whole stored chat, archived messages included, as the JSON list of messages the chat panel reads: each
     * message as Message::jsonSerialize() writes it. No placeholders: it shows what was actually stored.
     */
    public function transcriptJson(string $threadId): string
    {
        return (string) json_encode(array_map(static fn (Message $m): array => $m->jsonSerialize(), $this->loadAll($threadId)), JSON_THROW_ON_ERROR);
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

            return $deserializer->deserialize(array_merge(is_array($data) ? $data : [], [
                '__id' => $row['messageId'],
                'role' => $row['role'],
                'content' => $row['content'] !== null ? json_decode((string) $row['content'], true) : null,
            ]));
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
     * assistant reply that opens the context (what a trim can leave after a repaired pair). Tool calls and their
     * results are not touched.
     *
     * @param list<Message> $messages
     * @return list<Message>
     */
    private function repair(array $messages, int $chatId, bool $log = true): array
    {
        $repaired = [];
        $previousRole = 'assistant';
        $positions = [];

        foreach ($messages as $message) {
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
        }

        if ($log && $positions !== []) {
            \Concrete\Core\Support\Facade\Log::notice(SecretRedactor::redact(sprintf(
                'Katalysis Neuron AI: chat %d has messages in an order the model refuses; placeholders added in memory at position %s (nothing stored).',
                $chatId,
                implode(', ', $positions)
            )));
        }

        return $repaired;
    }

    private static function isPlain(Message $message): bool
    {
        return !$message instanceof ToolCallMessage && !$message instanceof ToolResultMessage;
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
