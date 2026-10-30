<?php

namespace Concrete\Package\KatalysisNeuronAi\Controller;

use Concrete\Core\Controller\AbstractController;
use Concrete\Core\Http\ResponseFactory;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Page\Page;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Chat\ChatMessageStore;
use Katalysis\NeuronAi\Chat\ChatPurge;
use Katalysis\NeuronAi\Chat\ChatRetention;
use Katalysis\NeuronAi\Chat\PurgeChatsCommand;
use Katalysis\NeuronAi\Chat\PurgeChatsCommandHandler;
use Katalysis\NeuronAi\Chat\TurnChanges;
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

    /** P3.9-2: the database lock the daily purge holds, so two requests never purge at once. */
    private const PURGE_LOCK = 'katalysis_neuron_ai_chat_purge';

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
                        // A call waiting for approval is not approved, and its run ends with the messages (P3.6-1).
                        $this->makeAgent()->setChatId($chat->getId())->discardRun();
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
            // Blocks the user has an editor open on, on this page: the tools leave them alone this turn (BF-7).
            $agent->setOpenEditorBlocks($this->extractOpenBlockIDs($data));

            $this->updateChatLocationFromContext($pageContext);

            // The agent puts a line naming the current page in front of the message. Neuron's store writes each
            // message as the turn commits it, so there is nothing to save afterwards. A failed turn is handled inside
            // handle(): it stores the fixed error reply and returns it.
            $response = $agent->handleUserMessage($message);

            return $this->json($this->turnBody($agent, $response) + [
                'history_count' => count($agent->getChatHistory()->getMessages())
            ]);
        } catch (\Throwable $e) {
            // Anything handle() didn't catch: the chat row, the agent's setup, the reply's history count.
            \Concrete\Core\Support\Facade\Log::error('Stack trace: ' . SecretRedactor::redact($e->getTraceAsString()));

            return $this->failed('Neuron AI Error', 'Unable to process the chat request at the moment.', $e);
        }
    }
    
    /**
     * The user's Approve or Deny on one call waiting for approval (P3.6-1, plans/P3.6-1.md §5): POST
     * {callId, decision: approve|deny, reason?, pageContext?, openBlockIDs?} with the panel's token. Only a call of
     * this session's chat, which the user started, can be decided, and only while it waits: otherwise 409, with the
     * cards still open. Typed text never approves anything; only this endpoint does.
     */
    public function decide()
    {
        if ($refusal = $this->refuseIfNotAllowed(true)) {
            return $refusal;
        }

        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $data = $this->body();
            $callId = $data['callId'] ?? null;
            $decision = $data['decision'] ?? null;
            $reason = $data['reason'] ?? null;
            if (!is_string($callId) || $callId === '' || strlen($callId) > 64 || !in_array($decision, ['approve', 'deny'], true)
                || ($reason !== null && !is_string($reason))) {
                return $this->json(['success' => false, 'error' => 'callId and decision (approve or deny) are required'], 400);
            }

            $u = new \Concrete\Core\User\User();
            $chat = $this->entityManager()->getRepository(ChatEntity::class)->findOneBy([
                'sessionId' => session_id(),
                'createdBy' => $u->isRegistered() ? $u->getUserID() : 0,
            ]);
            if (!$chat) {
                return $this->json(['success' => false, 'error' => 'There is no chat waiting for this decision.', 'code' => 'not_waiting'], 404);
            }

            $agent = $this->makeAgent()->setChatId($chat->getId());
            $agent->setRuntimePageContext($this->extractFrontendPageContext($data));
            $agent->setOpenEditorBlocks($this->extractOpenBlockIDs($data));

            $result = $agent->decide($callId, $decision === 'approve', $reason);
            if (!$result['recorded']) {
                return $this->json([
                    'success' => false,
                    'error' => 'This is no longer waiting for a decision.',
                    'code' => 'not_waiting',
                    'approvals' => $agent->getApprovalCards(),
                ], 409);
            }

            return $this->json($this->turnBody($agent, $result['reply']));
        } catch (\Throwable $e) {
            return $this->failed('Neuron AI approval error', 'Unable to record the decision at the moment.', $e);
        }
    }

    /**
     * What the panel gets after a turn or a decision. $response null: another call in the batch still waits.
     *
     * @return array<string, mixed>
     */
    private function turnBody(ConcreteCmsAgent $agent, ?string $response): array
    {
        return [
            'success' => true,
            'response' => $response,
            // The fixed notice text when a tool result held text addressed to the assistant, else null (P1.11).
            'notice' => $agent->getTurnNotice(),
            // The pages and blocks the turn changed, also when it failed: the panel reloads the page it is on (BF-7).
            'changes' => $agent->getTurnChanges(),
            // P3.6-1: the turn's "Changed: …" line, the calls waiting for a decision, and those this request settled.
            'changesLine' => $agent->getTurnChangesLine(),
            'awaitingApproval' => $agent->isAwaitingApproval(),
            'approvals' => $agent->getApprovalCards(),
            'settledApprovals' => $agent->getSettledApprovalCards(),
            'timestamp' => time(),
        ];
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

    /**
     * The block IDs the panel reports an editor open on (BF-7). Untrusted: only positive integers are kept, at most 50.
     * They can only make the tools refuse more.
     *
     * @return list<int>
     */
    private function extractOpenBlockIDs($data): array
    {
        $ids = is_array($data) && isset($data['openBlockIDs']) && is_array($data['openBlockIDs']) ? $data['openBlockIDs'] : [];
        $ids = array_filter($ids, static fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)));
        $ids = array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0);

        return array_slice(array_values(array_unique($ids)), 0, 50);
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
     * without the page tags and with the injection notices added. Only the person's messages and the final replies
     * (BF-11).
     */
    private function historyForPanel(int $chatId): string
    {
        $json = $this->messageStore()->conversationJson(ChatMessageStore::threadFor($chatId));

        // P3.6-1: each reply's stored "Changed: …" line too.
        return TurnChanges::annotateHistoryJson(InjectionNotice::annotateHistoryJson(PageContextTag::stripFromHistoryJson($json)));
    }

    /**
     * The chat's calls not yet sent on, for the panel's cards (P3.6-1).
     *
     * @return list<array<string, mixed>>
     */
    private function approvalCards(int $chatId): array
    {
        return \Katalysis\NeuronAi\Approval\ApprovalService::make()->openCards($chatId);
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
     * Load a specific chat by ID. It becomes the session's chat (BF-4), so the next message, "Delete current chat" and
     * a reload act on the chat on screen; that changes state, so the panel's token is needed.
     */
    public function load()
    {
        if ($refusal = $this->refuseIfNotAllowed(true)) {
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

            $this->makeSessionChat($chat, $entityManager);

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
                    'approvals' => $this->approvalCards($chat->getId()),
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Error loading chat', 'Unable to load this chat.', $e);
        }
    }

    /**
     * Binds the chat to this browser session: every action finds "the current chat" by its sessionId. The chat the
     * session had before is unbound (it stays in the saved list) and the chat leaves any other session it was in.
     */
    private function makeSessionChat(ChatEntity $chat, EntityManagerInterface $entityManager): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $sessionId = session_id();

        foreach ($entityManager->getRepository(ChatEntity::class)->findBy(['sessionId' => $sessionId]) as $other) {
            if ($other->getId() !== $chat->getId()) {
                $other->setSessionId(null);
            }
        }
        $chat->setSessionId($sessionId);
        $entityManager->flush();
    }

    /**
     * Load the current chat bound to this browser session.
     */
    public function current()
    {
        if ($refusal = $this->refuseIfNotAllowed(false)) {
            return $refusal;
        }
        // P3.9-2: the panel loads this on every page view, so the daily purge runs from here (after the response).
        $this->purgeOldChatsIfDue();

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
                    // P3.6-1: the calls still waiting for a decision, so a reload shows their cards.
                    'approvals' => $this->approvalCards($chat->getId()),
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

            // Its run is ended; then ChatPurge deletes it with its messages (P2.5), approval rows (P3.6-1) and the log
            // lines that name it (P3.9-2).
            $this->makeAgent()->setChatId($chat->getId())->discardRun();
            $entityManager->detach($chat);
            $this->chatPurge()->deleteChat($chatId);

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
                $this->makeAgent()->setChatId($chat->getId())->discardRun();
                $entityManager->detach($chat);
                $this->chatPurge()->deleteChat((int) $chat->getId());
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
    /**
     * P3.9-2 (B-PII-5): "Delete all my chats", in two posts with the confirmation in the panel. Without
     * `confirm_cutoff` it only counts the user's own chats and returns the cutoff it counted to; with it, it deletes the
     * user's chats last active up to that cutoff, no further. Only ever the current user's own chats.
     */
    public function delete_all_mine()
    {
        if ($refusal = $this->refuseIfNotAllowed(true)) {
            return $refusal;
        }

        try {
            $u = new \Concrete\Core\User\User();
            $userId = $u->isRegistered() ? (int) $u->getUserID() : 0;
            if ($userId <= 0) {
                return $this->json(['success' => false, 'error' => 'Only a signed-in user has chats to delete.'], 403);
            }
            $data = $this->body();
            $confirmed = is_string($data['confirm_cutoff'] ?? null) ? $data['confirm_cutoff'] : '';
            if ($confirmed === '') {
                // A moment from now, so the chat on screen is included; a chat used after counting is not.
                $cutoff = new \DateTimeImmutable('+1 second');

                return $this->json([
                    'success' => true,
                    'count' => $this->chatPurge()->count($cutoff, $userId),
                    'cutoff' => $cutoff->format('Y-m-d H:i:s'),
                ]);
            }

            $cutoff = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $confirmed);
            if (!$cutoff || $cutoff > new \DateTimeImmutable('+1 minute')) {
                return $this->json(['success' => false, 'error' => 'The confirmation was not valid. Nothing was deleted.'], 400);
            }
            $deleted = 0;
            do {
                $batch = $this->chatPurge()->purge($cutoff, $userId)['chats'];
                $deleted += $batch;
            } while ($batch >= ChatPurge::BATCH);
            unset($_SESSION['katalysis_neuron_ai_page_context']);

            return $this->json(['success' => true, 'deleted' => true, 'chats' => $deleted]);
        } catch (\Throwable $e) {
            return $this->failed('Error deleting all chats', 'Unable to delete your chats.', $e);
        }
    }

    /**
     * P3.9-2: the purge every delete goes through. It ends a waiting run through this request's agent (a test gives
     * its own purge).
     */
    protected function chatPurge(): ChatPurge
    {
        return new ChatPurge(Application::getFacadeApplication()->make(\Concrete\Core\Database\Connection\Connection::class), function (int $chatId): void {
            $this->makeAgent()->setChatId($chatId)->discardRun();
        });
    }

    /**
     * P3.9-2: the daily fallback for sites without the task scheduler's cron. At most once a day (ChatRetention),
     * after the response is sent, under a lock so two requests never purge at once. Never fails the request.
     */
    private function purgeOldChatsIfDue(): void
    {
        // Web requests only: the tests and the scenario runner call these actions in process, and the command line
        // has the task itself (task:purge-old-neuron-a-i-chats).
        if (PHP_SAPI === 'cli') {
            return;
        }
        try {
            $lastRun = \Concrete\Core\Support\Facade\Config::get(ChatRetention::LAST_RUN_KEY);
            if (!ChatRetention::fallbackDue(is_numeric($lastRun) ? (int) $lastRun : null, time())) {
                return;
            }
            register_shutdown_function(function (): void {
                try {
                    $db = Application::getFacadeApplication()->make(\Concrete\Core\Database\Connection\Connection::class);
                    if ((int) $db->fetchOne('SELECT GET_LOCK(?, 0)', [self::PURGE_LOCK]) !== 1) {
                        return;
                    }
                    try {
                        \Concrete\Core\Support\Facade\Config::save(ChatRetention::LAST_RUN_KEY, time());
                        (new PurgeChatsCommandHandler($this->chatPurge()))(new PurgeChatsCommand());
                    } finally {
                        $db->fetchOne('SELECT RELEASE_LOCK(?)', [self::PURGE_LOCK]);
                    }
                } catch (\Throwable $e) {
                    \Concrete\Core\Support\Facade\Log::warning('Katalysis Neuron AI: the daily chat purge failed: ' . SecretRedactor::redact($e->getMessage()));
                }
            });
        } catch (\Throwable) {
            // The fallback never fails the request.
        }
    }
}
