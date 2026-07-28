<?php

namespace Concrete\Package\KatalysisNeuronAi\Controller;

use Concrete\Core\Controller\AbstractController;
use Concrete\Core\Http\ResponseFactory;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\DatabaseChatHistory;
use Katalysis\NeuronAi\Entity\Chat as ChatEntity;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\History\InMemoryChatHistory;

class Chat extends AbstractController
{
    protected $helpers = [];
    
    public function send_message()
    {
        // Set proper content type before any output
        header('Content-Type: application/json');
        
        try {
            // Start session for agent state persistence
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Get JSON input
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            $message = $data['message'] ?? '';
            $clear = $data['clear'] ?? false;
            $pageContext = $this->extractFrontendPageContext($data);
            $_SESSION['katalysis_neuron_ai_page_context'] = $pageContext;
            
            if (empty($message) && !$clear) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Message is required'
                ]);
                exit;
            }
            
            // Handle clear conversation
            if ($clear) {
                // Clear database chat history
                try {
                    $app = Application::getFacadeApplication();
                    $entityManager = $app->make('Doctrine\ORM\EntityManager');
                    $sessionId = session_id();
                    
                    $chat = $entityManager->getRepository(ChatEntity::class)
                        ->findOneBy(['sessionId' => $sessionId]);
                    
                    if ($chat) {
                        // Clear the chat history in the database
                        $chat->setChatHistory('');
                        $chat->setFirstMessage('');
                        $chat->setLastMessage('');
                        $chat->setUserMessageCount(0);
                        $chat->setUpdatedDate(new \DateTime());
                        $entityManager->flush();
                    }
                } catch (\Exception $e) {
                    \Concrete\Core\Support\Facade\Log::error(
                        "Error clearing chat history: " . $e->getMessage()
                    );
                }
                
                // Also clear old session data for backward compatibility
                unset($_SESSION['neuron_agent_state']);
                unset($_SESSION['katalysis_neuron_ai_page_context']);
                
                echo json_encode([
                    'success' => true,
                    'response' => 'Conversation cleared',
                    'timestamp' => time()
                ]);
                exit;
            }
            
            // Create or restore agent
            $agent = $this->getOrRestoreAgent();
            $agent->setRuntimePageContext($pageContext);

            $this->updateChatLocationFromContext($pageContext);
            
            // Send message and get response
            $response = $agent->handle($message);
            
            // Save agent state (includes chat history)
            $this->saveAgentState($agent);
            
            echo json_encode([
                'success' => true,
                'response' => $response,
                'timestamp' => time(),
                'history_count' => count($agent->resolveState()->getChatHistory()->getMessages())
            ]);
            exit;
            
        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error('Neuron AI Chat Error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Unable to process the chat request at the moment.'
            ]);
            exit;
        }
    }
    
    /**
     * Get or create a chat ID for the current session
     * 
     * @return int Chat ID
     */
    private function getOrCreateChatId(): int
    {
        $app = Application::getFacadeApplication();
        $entityManager = $app->make('Doctrine\ORM\EntityManager');
        
        // Try to find existing chat by session ID
        $sessionId = session_id();
        
        $chat = $entityManager->getRepository(ChatEntity::class)
            ->findOneBy(['sessionId' => $sessionId]);
        
        if (!$chat) {
            // Create new chat
            $chat = new ChatEntity();
            $chat->setSessionId($sessionId);
            $chat->setStarted(new \DateTime());
            $chat->setCreatedDate(new \DateTime());
            
            // Get current user
            try {
                $u = new \Concrete\Core\User\User();
                $chat->setCreatedBy($u->isRegistered() ? $u->getUserID() : 0);
            } catch (\Exception $e) {
                $chat->setCreatedBy(0);
            }
            
            // Default location fallback when no frontend context has been sent yet.
            try {
                $c = Page::getCurrentPage();
                if ($c && !$c->isError()) {
                    $chat->setLocation($c->getCollectionPath());
                }
            } catch (\Exception $e) {
                // Ignore - might be CLI or bootstrap not complete
            }
            
            $entityManager->persist($chat);
            $entityManager->flush();
        }
        
        return $chat->getId();
    }

    private function extractFrontendPageContext($data): array
    {
        if (!is_array($data) || !isset($data['pageContext']) || !is_array($data['pageContext'])) {
            $data = ['pageContext' => []];
        }

        $context = $data['pageContext'];

        $resolvedId = null;
        if (isset($context['id']) && is_numeric($context['id'])) {
            $pageById = Page::getByID((int) $context['id'], 'ACTIVE');
            if ($pageById && !$pageById->isError()) {
                $resolvedId = (int) $pageById->getCollectionID();
            }
        }

        $path = null;
        if ($resolvedId !== null) {
            $pageById = Page::getByID($resolvedId, 'ACTIVE');
            $path = ($pageById && !$pageById->isError()) ? $pageById->getCollectionPath() : null;
        } elseif (!empty($context['path']) && is_string($context['path'])) {
            $path = parse_url($context['path'], PHP_URL_PATH) ?: $context['path'];
        } elseif (!empty($context['url']) && is_string($context['url'])) {
            $path = parse_url($context['url'], PHP_URL_PATH) ?: null;
        } else {
            $path = $this->getReferrerPath();
        }

        $cleanPath = $this->normalizePagePath($path);

        if ($resolvedId === null && $cleanPath !== null) {
            $page = Page::getByPath($cleanPath, 'ACTIVE');
            if ($page && !$page->isError()) {
                $resolvedId = (int) $page->getCollectionID();
            }
        }

        return [
            'id' => $resolvedId,
            'path' => $cleanPath,
            'url' => isset($context['url']) && is_string($context['url']) ? $context['url'] : null,
            'title' => isset($context['title']) && is_string($context['title']) ? trim($context['title']) : null,
        ];
    }

    private function getReferrerPath(): ?string
    {
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        if (!is_string($referrer) || $referrer === '') {
            return null;
        }

        $host = $_SERVER['HTTP_HOST'] ?? null;
        $refHost = parse_url($referrer, PHP_URL_HOST);
        if ($host && $refHost && !hash_equals((string) $host, (string) $refHost)) {
            return null;
        }

        $path = parse_url($referrer, PHP_URL_PATH);
        return is_string($path) ? $path : null;
    }

    private function normalizePagePath(?string $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        // Collapse duplicate slashes, but keep root slash.
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        // Strip query-like fragments if they slipped in.
        $path = explode('?', $path, 2)[0];
        $path = explode('#', $path, 2)[0];

        return $path === '' ? '/' : $path;
    }

    private function updateChatLocationFromContext(array $pageContext): void
    {
        $path = $pageContext['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return;
        }

        try {
            $app = Application::getFacadeApplication();
            $entityManager = $app->make('Doctrine\\ORM\\EntityManager');
            $sessionId = session_id();

            $chat = $entityManager->getRepository(ChatEntity::class)
                ->findOneBy(['sessionId' => $sessionId]);

            if (!$chat) {
                return;
            }

            $chat->setLocation($path);
            $chat->setUpdatedDate(new \DateTime());
            $entityManager->flush();
        } catch (\Throwable $e) {
            \Concrete\Core\Support\Facade\Log::warning(
                'Unable to update chat location from page context: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Get or restore agent with database-backed chat history
     */
    private function getOrRestoreAgent(): ConcreteCmsAgent
    {
        // Get or create chat ID for this session
        $chatId = $this->getOrCreateChatId();
        
        // Create agent first
        $agent = new ConcreteCmsAgent();
        
        // Then set the chat ID - this will automatically load existing messages
        $agent->setChatId($chatId);
        
        return $agent;
    }
    
    /**
     * Save agent state (automatic with DatabaseChatHistory)
     * 
     * DatabaseChatHistory automatically saves each message to the database.
     */
    private function saveAgentState(ConcreteCmsAgent $agent): void
    {
        $chatHistory = $agent->resolveState()->getChatHistory();

        if (!($chatHistory instanceof DatabaseChatHistory)) {
            \Concrete\Core\Support\Facade\Log::warning(
                "Chat history is not using DatabaseChatHistory - messages may not persist!"
            );
        }
    }
    
    /**
     * List all saved chats for the current user
     */
    public function chat_list()
    {
        header('Content-Type: application/json');
        
        try {
            $app = Application::getFacadeApplication();
            $entityManager = $app->make('Doctrine\ORM\EntityManager');
            
            // Get current user
            $u = new \Concrete\Core\User\User();
            $userId = $u->isRegistered() ? $u->getUserID() : 0;
            
            // Fetch chats for this user, ordered by most recent first
            $qb = $entityManager->createQueryBuilder();
            $qb->select('c')
               ->from(ChatEntity::class, 'c')
               ->where('c.createdBy = :userId')
               ->andWhere('c.chatHistory IS NOT NULL')
               ->andWhere('c.chatHistory != :empty')
               ->setParameter('userId', $userId)
               ->setParameter('empty', '')
               ->orderBy('c.updatedDate', 'DESC')
               ->setMaxResults(100); // Limit to 100 most recent chats
            
            $chats = $qb->getQuery()->getResult();
            
            // Format chats for JSON response
            $chatList = [];
            foreach ($chats as $chat) {
                $chatList[] = [
                    'id' => $chat->getId(),
                    'firstMessage' => $chat->getFirstMessage(),
                    'lastMessage' => $chat->getLastMessage(),
                    'createdDate' => $chat->getCreatedDate() ? $chat->getCreatedDate()->format('c') : null,
                    'updatedDate' => $chat->getUpdatedDate() ? $chat->getUpdatedDate()->format('c') : null,
                    'userMessageCount' => $chat->getUserMessageCount(),
                    'location' => $chat->getLocation()
                ];
            }
            
            echo json_encode([
                'success' => true,
                'chats' => $chatList
            ]);
            exit;
            
        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error('Error fetching chat list: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }
    
    /**
     * Load a specific chat by ID
     */
    public function load()
    {
        header('Content-Type: application/json');
        
        try {
            $chatId = $this->request->query->get('id');
            
            if (empty($chatId)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Chat ID is required'
                ]);
                exit;
            }
            
            $app = Application::getFacadeApplication();
            $entityManager = $app->make('Doctrine\ORM\EntityManager');
            
            // Get current user
            $u = new \Concrete\Core\User\User();
            $userId = $u->isRegistered() ? $u->getUserID() : 0;
            
            // Fetch the chat - ensure it belongs to the current user for security
            $chat = $entityManager->getRepository(ChatEntity::class)
                ->findOneBy([
                    'id' => $chatId,
                    'createdBy' => $userId
                ]);
            
            if (!$chat) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Chat not found or access denied'
                ]);
                exit;
            }
            
            // Return chat data
            echo json_encode([
                'success' => true,
                'chat' => [
                    'id' => $chat->getId(),
                    'chatHistory' => $chat->getChatHistory(),
                    'firstMessage' => $chat->getFirstMessage(),
                    'lastMessage' => $chat->getLastMessage(),
                    'createdDate' => $chat->getCreatedDate() ? $chat->getCreatedDate()->format('c') : null,
                    'updatedDate' => $chat->getUpdatedDate() ? $chat->getUpdatedDate()->format('c') : null,
                    'userMessageCount' => $chat->getUserMessageCount(),
                    'location' => $chat->getLocation()
                ]
            ]);
            exit;
            
        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error('Error loading chat: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * Load the current chat bound to this browser session.
     */
    public function current()
    {
        header('Content-Type: application/json');

        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $app = Application::getFacadeApplication();
            $entityManager = $app->make('Doctrine\\ORM\\EntityManager');

            $sessionId = session_id();
            $chat = $entityManager->getRepository(ChatEntity::class)
                ->findOneBy(['sessionId' => $sessionId]);

            if (!$chat) {
                echo json_encode([
                    'success' => true,
                    'chat' => null,
                    'sessionId' => $sessionId,
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'chat' => [
                    'id' => $chat->getId(),
                    'chatHistory' => $chat->getChatHistory(),
                    'firstMessage' => $chat->getFirstMessage(),
                    'lastMessage' => $chat->getLastMessage(),
                    'createdDate' => $chat->getCreatedDate() ? $chat->getCreatedDate()->format('c') : null,
                    'updatedDate' => $chat->getUpdatedDate() ? $chat->getUpdatedDate()->format('c') : null,
                    'userMessageCount' => $chat->getUserMessageCount(),
                    'location' => $chat->getLocation(),
                ],
                'sessionId' => $sessionId,
            ]);
            exit;
        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error('Error loading current chat: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
            exit;
        }
    }
    
    /**
     * Create a new chat session
     */
    public function new_chat()
    {
        header('Content-Type: application/json');
        
        try {
            // Start session for new chat
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Regenerate session ID to create a fresh session for new chat
            session_regenerate_id(true);
            
            // Clear any existing session data
            unset($_SESSION['neuron_agent_state']);
            unset($_SESSION['katalysis_neuron_ai_page_context']);
            
            echo json_encode([
                'success' => true,
                'message' => 'New chat session created',
                'sessionId' => session_id()
            ]);
            exit;
            
        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error('Error creating new chat: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * Delete a saved chat by ID for the current user.
     */
    public function delete_chat()
    {
        header('Content-Type: application/json');

        try {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            $chatId = isset($data['id']) ? (int) $data['id'] : 0;

            if ($chatId <= 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Valid chat ID is required'
                ]);
                exit;
            }

            $app = Application::getFacadeApplication();
            $entityManager = $app->make('Doctrine\\ORM\\EntityManager');

            $u = new \Concrete\Core\User\User();
            $userId = $u->isRegistered() ? $u->getUserID() : 0;

            $chat = $entityManager->getRepository(ChatEntity::class)
                ->findOneBy([
                    'id' => $chatId,
                    'createdBy' => $userId
                ]);

            if (!$chat) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Chat not found or access denied'
                ]);
                exit;
            }

            $entityManager->remove($chat);
            $entityManager->flush();

            echo json_encode([
                'success' => true,
                'id' => $chatId,
                'deleted' => true
            ]);
            exit;
        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error('Error deleting chat: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * Delete the current session-bound chat conversation.
     */
    public function delete_current_chat()
    {
        header('Content-Type: application/json');

        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $app = Application::getFacadeApplication();
            $entityManager = $app->make('Doctrine\\ORM\\EntityManager');

            $u = new \Concrete\Core\User\User();
            $userId = $u->isRegistered() ? $u->getUserID() : 0;
            $sessionId = session_id();

            $chat = $entityManager->getRepository(ChatEntity::class)
                ->findOneBy([
                    'sessionId' => $sessionId,
                    'createdBy' => $userId
                ]);

            if ($chat) {
                $entityManager->remove($chat);
                $entityManager->flush();
            }

            unset($_SESSION['neuron_agent_state']);
            unset($_SESSION['katalysis_neuron_ai_page_context']);

            echo json_encode([
                'success' => true,
                'deleted' => $chat ? true : false,
                'sessionId' => $sessionId
            ]);
            exit;
        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error('Error deleting current chat: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }
}