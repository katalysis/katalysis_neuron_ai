<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Concrete\Package\KatalysisNeuronAi\Controller\Chat;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\ConcreteCmsAgent;

/**
 * P3.11-2: a scenario's turns through the chat panel's own path, not straight into the agent: the chat endpoint
 * (controllers/chat.php, send_message) with the panel's payload, and every approval through the decide endpoint, in
 * process, as the browser sends them (js/chat-panel.js): the token header, the session that finds the chat, the page
 * context the panel builds (CCM_CID, location, title, URL) and the open editor blocks (none). BF-1's bug, the panel
 * sending no page ID, was invisible to the in-process runner; this path would have shown it.
 *
 * The endpoints make their agents with the runner's factory, so a dry run (--fake) and a stand-in provider work here
 * too. Each call is a new request with a new entity manager, as over HTTP.
 */
final class PanelPath
{
    /** @var list<string> the endpoints called, in order */
    private array $endpoints = [];

    /**
     * @param \Closure(): ConcreteCmsAgent $agentFactory
     * @param string $sessionId the scenario chat's session ID: the endpoints find "the current chat" by it
     */
    public function __construct(private readonly \Closure $agentFactory, private readonly string $sessionId)
    {
        // Concrete's router finds package controllers by path; here it is loaded directly.
        require_once dirname(__DIR__, 2) . '/controllers/chat.php';
    }

    /**
     * What the panel sends as pageContext for a page (getPageContext() in js/chat-panel.js). In edit mode Concrete's
     * address is index.php?cID=…&ctask=check-out-first, so the location is /index.php and the page is found by its ID.
     *
     * @return array{id: int, path: string, title: string, url: string}
     */
    public static function pageContext(Page $page, bool $editMode): array
    {
        $app = Application::getFacadeApplication();
        $base = rtrim((string) $app->make('site')->getSite()->getConfigRepository()->get('seo.canonical_url'), '/');
        $id = (int) $page->getCollectionID();
        $path = (string) $page->getCollectionPath();
        $path = $path === '' ? '/' : $path;
        $siteName = (string) $app->make('site')->getSite()->getSiteName();

        return [
            'id' => $id,
            'path' => $editMode ? '/index.php' : $path,
            // The browser's title, as Concrete's themes print it; the server doesn't trust it.
            'title' => (string) $page->getCollectionName() . ' :: ' . $siteName,
            'url' => $editMode ? $base . '/index.php?cID=' . $id . '&ctask=check-out-first' : $base . ($path === '/' ? '/' : $path),
        ];
    }

    /**
     * A message, as the panel's send button posts it.
     *
     * @param array<string, mixed> $pageContext
     * @return array<string, mixed> the endpoint's JSON body
     */
    public function send(string $message, array $pageContext): array
    {
        return $this->post('send_message', ['message' => $message, 'pageContext' => $pageContext, 'openBlockIDs' => []]);
    }

    /**
     * An Approve or Deny, as the card's button posts it.
     *
     * @param array<string, mixed> $pageContext
     * @return array<string, mixed> the endpoint's JSON body
     */
    public function decide(string $callId, string $decision, array $pageContext): array
    {
        return $this->post('decide', ['callId' => $callId, 'decision' => $decision, 'pageContext' => $pageContext, 'openBlockIDs' => []]);
    }

    /** @return list<string> */
    public function endpoints(): array
    {
        return $this->endpoints;
    }

    /** The user has the page in edit mode (Concrete's check-out row), as after clicking Edit. */
    public static function checkOut(Page $page, int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        Application::getFacadeApplication()->make(Connection::class)->executeStatement(
            'UPDATE Pages SET cIsCheckedOut = 1, cCheckedOutUID = ?, cCheckedOutDatetime = ?, cCheckedOutDatetimeLastEdit = ? WHERE cID = ?',
            [$userId, $now, $now, (int) $page->getCollectionID()]
        );
    }

    /** Leaves edit mode. Never throws, so it can run in a `finally`. */
    public static function checkIn(Page $page): void
    {
        try {
            Application::getFacadeApplication()->make(Connection::class)->executeStatement(
                'UPDATE Pages SET cIsCheckedOut = 0, cCheckedOutUID = NULL, cCheckedOutDatetime = NULL, cCheckedOutDatetimeLastEdit = NULL WHERE cID = ?',
                [(int) $page->getCollectionID()]
            );
        } catch (\Throwable) {
            // The next fixture reset makes the page again.
        }
    }

    /** Ends the session the endpoints used, and removes its file. */
    public static function endSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the endpoint doesn't answer 200
     */
    private function post(string $action, array $body): array
    {
        $this->openSession();
        $app = Application::getFacadeApplication();
        $request = Request::create('/ccm/system/katalysis_neuron_ai/chat/' . $action, 'POST', [], [], [], [], (string) json_encode($body));
        $request->headers->set(ChatAccess::TOKEN_HEADER, ChatAccess::generateToken());
        $factory = $this->agentFactory;
        $controller = new class ($factory) extends Chat {
            public function __construct(private readonly \Closure $factory)
            {
                parent::__construct();
            }

            protected function makeAgent(): ConcreteCmsAgent
            {
                return ($this->factory)();
            }
        };
        $controller->setRequest($request);
        // A new request over HTTP has a new entity manager and empty request caches: nothing is read from an earlier
        // request's. The caches go with the entities, or they hand out entities the manager no longer knows.
        $app->make(EntityManagerInterface::class)->clear();
        $app->make('cache/request')->flush();
        CacheLocal::flush();
        $this->endpoints[] = $action;

        $response = $controller->$action();
        $data = json_decode((string) $response->getContent(), true);
        if ($response->getStatusCode() !== 200 || !is_array($data)) {
            throw new \RuntimeException("The panel's {$action} answered " . $response->getStatusCode() . ': ' . (is_array($data) ? (string) ($data['error'] ?? '') : 'not JSON'));
        }

        return $data;
    }

    /** The browser's session, the chat's: started (or restarted) with the chat's session ID. */
    private function openSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && session_id() === $this->sessionId) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_id($this->sessionId);
        @session_start(['use_cookies' => 0, 'use_only_cookies' => 0, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
    }
}
