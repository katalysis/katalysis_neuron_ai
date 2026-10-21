<?php

namespace Katalysis\NeuronAi;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Entity\Chat;

/**
 * Database-based chat history implementation for Neuron AI
 * 
 * Stores conversation history in MySQL instead of files or PHP sessions.
 * Each message is automatically persisted to the database, enabling:
 * - Long-term storage beyond session lifetime
 * - Analytics and reporting on conversations
 * - Resume conversations across browser sessions
 * - Search and filter capabilities
 *
 * Neuron 3 only. Neuron 4 has no AbstractChatHistory, and Doctrine's annotation driver require_once()s every file
 * under src/, so an unconditional `extends` here returns 500 on every page (P2.1 findings 3.3). The class is declared
 * only when its parent exists; P2.5 replaces it with a Neuron 4 message store and removes this file.
 */
if (class_exists(AbstractChatHistory::class)) {
class DatabaseChatHistory extends AbstractChatHistory
{
    private $app;
    private $entityManager;
    private ?int $chatId = null;
    private ?Chat $chat = null;

    /**
     * Create a new database-backed chat history
     * 
     * @param int $contextWindow Maximum tokens to keep in context
     */
    public function __construct(int $contextWindow = 50000)
    {
        parent::__construct($contextWindow);
        
        $this->app = Application::getFacadeApplication();
        $this->entityManager = $this->app->make('Doctrine\ORM\EntityManager');
    }

    /**
     * Set the chat ID for this history instance and load messages
     * 
     * @param int $chatId Database ID of the chat to load
     * @return self
     */
    public function setChatId(int $chatId): self
    {
        $this->chatId = $chatId;
        $this->loadExistingMessages();
        
        return $this;
    }

    /**
     * Get the current chat ID
     * 
     * @return int|null
     */
    public function getChatId(): ?int
    {
        return $this->chatId;
    }

    /**
     * Get the current Chat entity
     * 
     * @return Chat|null
     */
    public function getChat(): ?Chat
    {
        return $this->chat;
    }

    /**
     * Load existing messages from the database
     * 
     * Deserializes JSON chat history into Neuron AI Message objects
     */
    private function loadExistingMessages(): void
    {
        if (!$this->chatId) {
            return;
        }

        try {
            $this->chat = $this->entityManager->find(Chat::class, $this->chatId);
            
            if (!$this->chat) {
                $this->history = [];
                return;
            }
            
            $chatHistory = $this->chat->getChatHistory();
            
            if (!$chatHistory) {
                $this->history = [];
                return;
            }
            
            $historyData = json_decode($chatHistory, true);
            
            if (!is_array($historyData) || empty($historyData)) {
                $this->history = [];
                return;
            }

            [$historyData, $wasRepaired] = $this->repairMessageSequence($historyData);
            
            $this->history = $this->deserializeMessages($historyData);

            if ($wasRepaired) {
                $this->persistHistory();
                \Concrete\Core\Support\Facade\Log::notice(
                    'DatabaseChatHistory: Auto-repaired invalid message sequence for chat ID ' . $this->chatId
                );
            }

        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error(
                'DatabaseChatHistory: Failed to load messages for chat ID ' . 
                $this->chatId . ': ' . $e->getMessage()
            );
            $this->history = [];
        }
    }

    protected function onNewMessage(Message $message): void
    {
        // Intentionally no-op: persistence is handled in setMessages(),
        // which is always called by AbstractChatHistory::addMessage().
    }

    /**
     * Puts a metadata value on the most recent message and stores the history again, if that message is a plain
     * assistant reply (not a tool call and not a user message). Used for the injection notice (P1.11), which belongs to
     * the turn's final reply. Anything else as the last message is left alone, so an earlier turn's reply is never
     * flagged by mistake.
     *
     * Metadata is written into the stored message JSON (Message::jsonSerialize) and read back by
     * AbstractChatHistory::deserializeMeta, so it survives a reload. Neuron does not send it to the provider.
     *
     * @param array<string, string> $more further metadata values to put on the same message
     *
     * @return bool whether a message was flagged
     */
    public function flagLastAssistantMessage(string $key, string $value, array $more = []): bool
    {
        $last = end($this->history);
        if (!$last instanceof Message || $last instanceof ToolCallMessage || $last->getRole() !== 'assistant') {
            return false;
        }

        $last->addMetadata($key, $value);
        foreach ($more as $k => $v) {
            $last->addMetadata((string) $k, $v);
        }
        $this->persistHistory();

        return true;
    }

    protected function onTrimHistory(int $index): void
    {
        // History has changed after trimming, persist the updated message set.
        $this->persistHistory();
    }

    protected function setMessages(array $messages): void
    {
        // Called by AbstractChatHistory on every addMessage().
        $this->persistHistory();
    }

    /**
     * Compatibility hook for Neuron builds that use storeMessage().
     */
    protected function storeMessage(Message $message): self
    {
        $this->persistHistory();
        return $this;
    }

    /**
     * Compatibility hook for Neuron builds that use removeOldMessage().
     */
    public function removeOldMessage(int $index): self
    {
        $this->persistHistory();
        return $this;
    }

    private function persistHistory(): void
    {
        if (!$this->chatId) {
            return;
        }

        try {
            if (!$this->chat) {
                $this->chat = $this->entityManager->find(Chat::class, $this->chatId);
            }

            if (!$this->chat) {
                return;
            }

            $messagesData = array_map(function (Message $msg) {
                return $msg->jsonSerialize();
            }, $this->history);

            $this->chat->setChatHistory(json_encode($messagesData, JSON_PRETTY_PRINT));
            $this->chat->setUpdatedDate(new \DateTime());

            $userMessages = array_values(array_filter($this->history, function (Message $msg) {
                return $msg->getRole() === 'user';
            }));

            $this->chat->setUserMessageCount(count($userMessages));

            if (!empty($userMessages)) {
                // The page tag is for the model; the chat list shows what the person typed.
                $firstUserText = PageContextTag::strip($this->messageToText($userMessages[0]));
                $lastUserText = PageContextTag::strip($this->messageToText($userMessages[count($userMessages) - 1]));
                $this->chat->setFirstMessage(substr($firstUserText, 0, 500));
                $this->chat->setLastMessage(substr($lastUserText, 0, 500));
            }

            $this->entityManager->flush();
        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error(
                'DatabaseChatHistory: Failed to persist history: ' . $e->getMessage()
            );
        }
    }

    private function messageToText(Message $message): string
    {
        $content = $message->getContent();

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_object($block) && method_exists($block, 'getContent')) {
                    $parts[] = (string) $block->getContent();
                } elseif (is_scalar($block)) {
                    $parts[] = (string) $block;
                }
            }
            return trim(implode(" ", $parts));
        }

        if (is_object($content) && method_exists($content, 'getContent')) {
            return (string) $content->getContent();
        }

        return (string) $content;
    }

    /**
     * Ensure persisted history alternates user/assistant.
     *
     * Some provider failures can leave the history ending with a user message,
     * and repeated failures can create user-user (or assistant-assistant) pairs.
     * This method inserts lightweight placeholder messages so the sequence is valid.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function repairMessageSequence(array $messages): array
    {
        $repaired = [];
        $expectingUser = true;
        $wasRepaired = false;

        foreach ($messages as $message) {
            if (!is_array($message)) {
                $wasRepaired = true;
                continue;
            }

            $role = $message['role'] ?? null;
            if (!is_string($role) || ($role !== 'user' && $role !== 'assistant')) {
                $wasRepaired = true;
                continue;
            }

            $expectedRole = $expectingUser ? 'user' : 'assistant';
            if ($role !== $expectedRole) {
                $repaired[] = [
                    'role' => $expectedRole,
                    'content' => $expectedRole === 'assistant'
                        ? '[Recovered: Assistant response was interrupted]'
                        : '[Recovered: User message was lost]',
                ];
                $expectingUser = !$expectingUser;
                $wasRepaired = true;
            }

            $repaired[] = $message;
            $expectingUser = !$expectingUser;
        }

        return [$repaired, $wasRepaired];
    }

    /**
     * Clear all messages from the database
     * 
     * Called when the chat is reset
     */
    protected function clear(): void
    {
        if ($this->chatId && $this->chat) {
            try {
                $this->chat->setChatHistory('');
                $this->chat->setFirstMessage('');
                $this->chat->setLastMessage('');
                $this->chat->setUserMessageCount(0);
                $this->chat->setUpdatedDate(new \DateTime());
                $this->entityManager->flush();
            } catch (\Exception $e) {
                \Concrete\Core\Support\Facade\Log::error(
                    'DatabaseChatHistory: Failed to clear messages: ' . $e->getMessage()
                );
            }
        }
    }
}
}
