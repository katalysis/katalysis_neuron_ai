<?php

namespace Katalysis\NeuronAi;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;
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
 */
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
            
            $this->history = $this->deserializeMessages($historyData);

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
                $firstUserText = $this->messageToText($userMessages[0]);
                $lastUserText = $this->messageToText($userMessages[count($userMessages) - 1]);
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
