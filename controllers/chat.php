<?php

namespace Concrete\Package\KatalysisNeuronAi\Controller;

use Concrete\Core\Controller\AbstractController;
use Concrete\Core\Http\ResponseFactory;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Page\Page;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Chat\ChatMessageStore;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat as ChatEntity;
use Katalysis\NeuronAi\PageContextResolver;
use Katalysis\NeuronAi\PageContextTag;
use Katalysis\NeuronAi\Security\InjectionNotice;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The chat panel's endpoints. Every action returns a JSON response (P2.6: no echo and exit, so the actions can be
 * tested in process). On a failure the browser gets a fixed message only, never the exception's text, a trace or a
 * path; the log gets the redacted message (B-SEC-1).
 */
class Chat extends AbstractController
{
    protected $helpers = [];

    private function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status);
    }

    /**
     * The 500 reply for a failed action: the fixed message for the browser, the redacted exception for the log.
     */
    private function failed(string $what, string $message, \Throwable $e): JsonResponse
    {
        \Concrete\Core\Support\Facade\Log::error($what . ': ' . SecretRedactor::redact($e->getMessage()));

        return $this->json(['success' => false, 'error' => $message], 500);
    }

    /**
     * The JSON body of a POST, or an empty array.
     */
    private function body(): array
    {
        $data = json_decode((string) $this->request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Where the chats are. A method so a test can make it fail.
     */
    protected function entityManager(): EntityManagerInterface
    {
        return Application::getFacadeApplication()->make(EntityManagerInterface::class);
    }

    /**
     * The agent for this request, not yet bound to a chat. A method so a test can give it a fake provider.
     */
    protected function makeAgent(): ConcreteCmsAgent
    {
        return new ConcreteCmsAgent();
    }

    /**
     * A fresh session ID for a new chat; the old session's data is dropped. A method so a test can make it fail.
     */
    protected function newSessionId(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);

        return session_id();
    }

    /**
     * The access check every action runs first (B-ACC-1), before any work and before
     * the session is started. State-changing (POST) actions also need the CSRF token
     * the chat panel sends in the X-Neuron-Token header.
     *
     * @return \Symfony\Component\HttpFoundation\Response|null a 403 response to return, or null if the request may go ahead
     */
    private function refuseIfNotAllowed(bool $isStateChanging)
    {
        $error = ChatAccess::refusalReason(
            $isStateChanging,
            $this->request->headers->get(ChatAccess::TOKEN_HEADER)
        );
        if ($error === null) {
            return null;
        }

        return Application::getFacadeApplication()->make(ResponseFactory::class)->json([
            'success' => false,
            'error' => $error,
            'code' => $error === ChatAccess::TOKEN_ERROR ? ChatAccess::TOKEN_ERROR_CODE : 'access_denied',
        ], 403);
    }

    public function send_message()
    {
        if ($refusal = $this->refuseIfNotAllowed(true)) {
            return $refusal;
        }

        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $data = $this->body();
            $message = $data['message'] ?? '';
            $clear = $data['clear'] ?? false;
            $pageContext = $this->extractFrontendPageContext($data);
            $_SESSION['katalysis_neuron_ai_page_context'] = $pageContext;

            if ((!is_string($message) || trim($message) === '') && !$clear) {
                return $this->json(['success' => false, 'error' => 'Message is required'], 400);
            }

            // Handle clear conversation
            if ($clear) {
                try {
                    $chat = $this->entityManager()->getRepository(ChatEntity::class)
                        ->findOneBy(['sessionId' => session_id()]);

                    if ($chat) {
                        // Clear the chat's messages; the store also resets the chat list's columns.
                        $this->messageStore()->clear(ChatMessageStore::threadFor($chat->getId()));
                    }
                } catch (\Throwable $e) {
                    \Concrete\Core\Support\Facade\Log::error(
                        'Error clearing chat history: ' . SecretRedactor::redact($e->getMessage())
                    );
                }

                unset($_SESSION['katalysis_neuron_ai_page_context']);

                return $this->json([
                    'success' => true,
                    'response' => 'Conversation cleared',
                    'timestamp' => time()
                ]);
            }

            $agent = $this->agentForThisRequest();
            $agent->setRuntimePageContext($pageContext);

            $this->updateChatLocationFromContext($pageContext);

            // The agent puts a line naming the current page in front of the message. Neuron's store writes each
            // message as the turn commits it, so there is nothing to save afterwards. A failed turn is handled inside
            // handle(): it stores the fixed error reply and returns it.
            $response = $agent->handleUserMessage($message);

            return $this->json([
                'success' => true,
                'response' => $response,
                // The fixed notice text when a tool result held text addressed to the assistant, else null (P1.11).
                'notice' => $agent->getTurnNotice(),
                'timestamp' => time(),
                'history_count' => count($agent->getChatHistory()->getMessages())
            ]);
        } catch (\Throwable $e) {
            // Anything handle() didn't catch: the chat row, the agent's setup, the reply's history count.
            \Concrete\Core\Support\Facade\Log::error('Stack trace: ' . SecretRedactor::redact($e->getTraceAsString()));

            return $this->failed('Neuron AI Error', 'Unable to process the chat request at the moment.', $e);
        }
    }
    
    /**
     * Get or create a chat ID for the current session
     * 
     * @return int Chat ID
     */
    private function getOrCreateChatId(): int
    {
        $entityManager = $this->entityManager();

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

    /**
     * The page the user is on, from the panel's pageContext (BF-1). PageContextResolver treats it as untrusted: only a
     * page the user can view is used.
     */
    private function extractFrontendPageContext($data): array
    {
        $payload = is_array($data) && isset($data['pageContext']) && is_array($data['pageContext']) ? $data['pageContext'] : [];
        $referrerPath = PageContextResolver::referrerPath($_SERVER['HTTP_REFERER'] ?? null, $_SERVER['HTTP_HOST'] ?? null);

        return (new PageContextResolver())->fromBrowser($payload, $referrerPath);
    }

    private function updateChatLocationFromContext(array $pageContext): void
    {
        $path = $pageContext['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return;
        }

        try {
            $entityManager = $this->entityManager();
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
                'Unable to update chat location from page context: ' . SecretRedactor::redact($e->getMessage())
            );
        }
    }

    /**
     * The chats' messages. A method so a test can make it fail.
     */
    protected function messageStore(): ChatMessageStore
    {
        return new ChatMessageStore(Application::getFacadeApplication()->make(\Concrete\Core\Database\Connection\Connection::class));
    }

    /**
     * The chat's stored messages as the JSON list the panel reads (P2.5: the message rows, archived ones included),
     * without the page tags and with the injection notices added.
     */
    private function historyForPanel(int $chatId): string
    {
        $json = $this->messageStore()->transcriptJson(ChatMessageStore::threadFor($chatId));

        return InjectionNotice::annotateHistoryJson(PageContextTag::stripFromHistoryJson($json));
    }

    /**
     * A new agent for this request, bound to the session's chat before anything else (P2.6). Neuron 4's chat() refuses
     * to run without a thread, and an agent's thread is bound once, so every request gets its own agent; the chat's
     * messages are read back from the store (ChatMessageStore) on its first use.
     */
    private function agentForThisRequest(): ConcreteCmsAgent
    {
        $chatId = $this->getOrCreateChatId();

        return $this->makeAgent()->setChatId($chatId);
    }

    /**
     * List all saved chats for the current user
     */
    public function chat_list()
    {
        if ($refusal = $this->refuseIfNotAllowed(false)) {
            return $refusal;
        }

        try {
            $entityManager = $this->entityManager();

            // Get current user
            $u = new \Concrete\Core\User\User();
            $userId = $u->isRegistered() ? $u->getUserID() : 0;

            // Fetch chats for this user, ordered by most recent first
            $qb = $entityManager->createQueryBuilder();
            $qb->select('c')
               ->from(ChatEntity::class, 'c')
               ->where('c.createdBy = :userId')
               ->andWhere('c.userMessageCount > 0')
               ->setParameter('userId', $userId)
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
            
            return $this->json([
                'success' => true,
                'chats' => $chatList
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Error fetching chat list', 'Unable to load your chats.', $e);
        }
    }
    
    /**
     * Load a specific chat by ID
     */
    public function load()
    {
        if ($refusal = $this->refuseIfNotAllowed(false)) {
            return $refusal;
        }

        try {
            $chatId = $this->request->query->get('id');

            if (empty($chatId)) {
                return $this->json(['success' => false, 'error' => 'Chat ID is required'], 400);
            }

            $entityManager = $this->entityManager();

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
                return $this->json(['success' => false, 'error' => 'Chat not found or access denied'], 404);
            }

            return $this->json([
                'success' => true,
                'chat' => [
                    'id' => $chat->getId(),
                    'chatHistory' => $this->historyForPanel($chat->getId()),
                    'firstMessage' => $chat->getFirstMessage(),
                    'lastMessage' => $chat->getLastMessage(),
                    'createdDate' => $chat->getCreatedDate() ? $chat->getCreatedDate()->format('c') : null,
                    'updatedDate' => $chat->getUpdatedDate() ? $chat->getUpdatedDate()->format('c') : null,
                    'userMessageCount' => $chat->getUserMessageCount(),
                    'location' => $chat->getLocation()
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Error loading chat', 'Unable to load this chat.', $e);
        }
    }

    /**
     * Load the current chat bound to this browser session.
     */
    public function current()
    {
        if ($refusal = $this->refuseIfNotAllowed(false)) {
            return $refusal;
        }

        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $entityManager = $this->entityManager();

            $sessionId = session_id();
            $chat = $entityManager->getRepository(ChatEntity::class)
                ->findOneBy(['sessionId' => $sessionId]);

            if (!$chat) {
                return $this->json([
                    'success' => true,
                    'chat' => null,
                    'sessionId' => $sessionId,
                ]);
            }

            return $this->json([
                'success' => true,
                'chat' => [
                    'id' => $chat->getId(),
                    'chatHistory' => $this->historyForPanel($chat->getId()),
                    'firstMessage' => $chat->getFirstMessage(),
                    'lastMessage' => $chat->getLastMessage(),
                    'createdDate' => $chat->getCreatedDate() ? $chat->getCreatedDate()->format('c') : null,
                    'updatedDate' => $chat->getUpdatedDate() ? $chat->getUpdatedDate()->format('c') : null,
                    'userMessageCount' => $chat->getUserMessageCount(),
                    'location' => $chat->getLocation(),
                ],
                'sessionId' => $sessionId,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Error loading current chat', 'Unable to load the current chat.', $e);
        }
    }
    
    /**
     * Create a new chat session
     */
    public function new_chat()
    {
        if ($refusal = $this->refuseIfNotAllowed(true)) {
            return $refusal;
        }

        try {
            // A fresh session ID: the next message starts a new chat.
            $sessionId = $this->newSessionId();

            unset($_SESSION['katalysis_neuron_ai_page_context']);

            return $this->json([
                'success' => true,
                'message' => 'New chat session created',
                'sessionId' => $sessionId
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Error creating new chat', 'Unable to start a new chat.', $e);
        }
    }

    /**
     * Delete a saved chat by ID for the current user.
     */
    public function delete_chat()
    {
        if ($refusal = $this->refuseIfNotAllowed(true)) {
            return $refusal;
        }

        try {
            $data = $this->body();
            $chatId = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;

            if ($chatId <= 0) {
                return $this->json(['success' => false, 'error' => 'Valid chat ID is required'], 400);
            }

            $entityManager = $this->entityManager();

            $u = new \Concrete\Core\User\User();
            $userId = $u->isRegistered() ? $u->getUserID() : 0;

            $chat = $entityManager->getRepository(ChatEntity::class)
                ->findOneBy([
                    'id' => $chatId,
                    'createdBy' => $userId
                ]);

            if (!$chat) {
                return $this->json(['success' => false, 'error' => 'Chat not found or access denied'], 404);
            }

            // Its message rows go with it (ON DELETE CASCADE, P2.5).
            $entityManager->remove($chat);
            $entityManager->flush();

            return $this->json([
                'success' => true,
                'id' => $chatId,
                'deleted' => true
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Error deleting chat', 'Unable to delete this chat.', $e);
        }
    }

    /**
     * Delete the current session-bound chat conversation.
     */
    public function delete_current_chat()
    {
        if ($refusal = $this->refuseIfNotAllowed(true)) {
            return $refusal;
        }

        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $entityManager = $this->entityManager();

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

            unset($_SESSION['katalysis_neuron_ai_page_context']);

            return $this->json([
                'success' => true,
                'deleted' => $chat ? true : false,
                'sessionId' => $sessionId
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Error deleting current chat', 'Unable to delete the current chat.', $e);
        }
    }
}