<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\PageContextTag;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\PaginatesMessages;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\UniqueIdGenerator;

/**
 * Neuron 4's message store on our existing table (P2.1 spike, plan section 5). No new table and no migration.
 *
 * A thread is one Chat row, named "chat-<id>". Its messages stay one JSON list in KatalysisNeuronAiChats.chatHistory,
 * as v3 wrote them; Neuron 4 reads that shape. Archived messages stay in the list, marked with archived_at, so
 * loadActive() leaves them out and loadAll() still has the whole transcript (FileMessageStore's design).
 *
 * Loading never writes: a stored row changes only when a message is appended, archived or flagged, or the thread is
 * cleared. The counters and the chat list's first and last message are kept as DatabaseChatHistory kept them.
 */
final class ChatMessageStore implements MessageStoreInterface
{
    use PaginatesMessages;

    private const PREFIX = 'chat-';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function threadFor(int $chatId): string
    {
        return self::PREFIX . $chatId;
    }

    public function loadActive(string $threadId): array
    {
        return $this->deserialize(array_filter($this->read($threadId), static fn (array $entry): bool => !isset($entry['archived_at'])));
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        return $this->paginate($this->deserialize($this->read($threadId)), $limit, $before);
    }

    public function append(string $threadId, Message $message): void
    {
        $entries = $this->read($threadId);
        foreach ($entries as $entry) {
            if ($entry['__id'] === $message->getId()) {
                return;
            }
        }
        $entries[] = $message->jsonSerialize();

        $this->write($threadId, $entries);
    }

    public function archive(string $threadId, int $count): void
    {
        $entries = $this->read($threadId);
        $archivedAt = date(DATE_ATOM);
        foreach ($entries as $i => $entry) {
            if ($count <= 0) {
                break;
            }
            if (!isset($entry['archived_at'])) {
                $entries[$i]['archived_at'] = $archivedAt;
                $count--;
            }
        }

        $this->write($threadId, $entries);
    }

    public function clear(string $threadId): void
    {
        $this->write($threadId, []);
    }

    /**
     * Puts metadata on the thread's last message, if that is a plain assistant reply (not a tool call). The injection
     * notice (P1.11) belongs to the turn's final reply. Not part of Neuron's store contract, which is append-only:
     * called after a turn has ended, never while one runs.
     *
     * @param array<string, string> $more
     *
     * @return bool whether a message was flagged
     */
    public function flagLastAssistantMessage(string $threadId, string $key, string $value, array $more = []): bool
    {
        $entries = $this->read($threadId);
        $last = array_key_last($entries);
        if ($last === null || ($entries[$last]['role'] ?? null) !== 'assistant' || isset($entries[$last]['type'])) {
            return false;
        }
        $meta = is_array($entries[$last]['__meta'] ?? null) ? $entries[$last]['__meta'] : [];
        $entries[$last]['__meta'] = array_merge($meta, [$key => $value], $more);

        $this->write($threadId, $entries);

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $threadId): array
    {
        $json = $this->chat($threadId)->getChatHistory();
        if (trim($json) === '') {
            return [];
        }
        $entries = json_decode($json, true);
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new ChatHistoryException("The stored history of thread {$threadId} is not a list of messages.");
        }
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                throw new ChatHistoryException("The stored history of thread {$threadId} holds a message that is not an object.");
            }
            // In memory only: the next write stores it (FileMessageStore does the same).
            $entries[$i]['__id'] ??= UniqueIdGenerator::generateId('msg_');
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function write(string $threadId, array $entries): void
    {
        $chat = $this->chat($threadId);
        $chat->setChatHistory($entries === [] ? '' : (string) json_encode($entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $chat->setUpdatedDate(new \DateTime());

        // As DatabaseChatHistory counted: every stored user-role message, tool results included.
        $users = array_values(array_filter($entries, static fn (array $e): bool => ($e['role'] ?? null) === 'user'));
        $chat->setUserMessageCount(count($users));
        $chat->setFirstMessage($users === [] ? '' : mb_substr(PageContextTag::strip(self::text($users[0])), 0, 500));
        $chat->setLastMessage($users === [] ? '' : mb_substr(PageContextTag::strip(self::text($users[count($users) - 1])), 0, 500));

        $this->entityManager->flush();
    }

    private function chat(string $threadId): Chat
    {
        if (!str_starts_with($threadId, self::PREFIX) || !ctype_digit(substr($threadId, strlen(self::PREFIX)))) {
            throw new ChatHistoryException("Unknown thread \"{$threadId}\": threads are named chat-<id>.");
        }
        $chat = $this->entityManager->find(Chat::class, (int) substr($threadId, strlen(self::PREFIX)));
        if (!$chat instanceof Chat) {
            throw new ChatHistoryException("No chat for thread \"{$threadId}\".");
        }

        return $chat;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function text(array $entry): string
    {
        $content = $entry['content'] ?? '';
        if (is_string($content)) {
            return $content;
        }
        $parts = [];
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && is_string($block['content'] ?? null)) {
                $parts[] = $block['content'];
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     *
     * @return Message[]
     */
    private function deserialize(array $entries): array
    {
        $deserializer = new MessageDeserializer();

        return array_values(array_map(static function (array $entry) use ($deserializer): Message {
            unset($entry['archived_at']);

            return $deserializer->deserialize($entry);
        }, $entries));
    }
}
