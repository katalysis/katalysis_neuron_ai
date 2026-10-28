<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Concrete\Package\KatalysisNeuronAi\Controller\Chat;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

/**
 * P3.6-1 (plans/P3.6-1.md §5): the panel's decide endpoint and the approval cards the chat endpoints return, called on
 * the controller as the panel calls it (ChatControllerTest's way). Only the agent's provider is replaced.
 */
final class ApprovalControllerTest extends ConcreteTestCase
{
    private const SESSION_PREFIX = 'p361ctl-';

    private ThrowawayPages $pages;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 2) . '/controllers/chat.php';
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        $this->startSession();
    }

    protected function tearDown(): void
    {
        foreach ($this->db()->fetchFirstColumn('SELECT id FROM KatalysisNeuronAiChats WHERE sessionId LIKE ?', [self::SESSION_PREFIX . '%']) as $id) {
            (new ConcreteCmsAgent())->setChatId((int) $id)->discardRun();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->app()->make(EntityManagerInterface::class)->clear();
        $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE sessionId LIKE ?', [self::SESSION_PREFIX . '%']);
        $this->pages->restore();
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_id(self::SESSION_PREFIX . bin2hex(random_bytes(6)));
        @session_start(['use_cookies' => 0, 'use_only_cookies' => 0, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function controller(FakeAIProvider $provider): Chat
    {
        return new class ($provider) extends Chat {
            public function __construct(private readonly FakeAIProvider $provider)
            {
                parent::__construct();
            }

            protected function makeAgent(): ConcreteCmsAgent
            {
                return (new ConcreteCmsAgent())->setAiProvider($this->provider);
            }
        };
    }

    /**
     * @return array{int, array<string, mixed>, string}
     */
    private function call(Chat $controller, string $action, ?array $json = null, ?string $token = 'valid'): array
    {
        $isPost = in_array($action, ['send_message', 'decide'], true);
        $request = Request::create('/ccm/system/katalysis_neuron_ai/chat/' . $action, $isPost ? 'POST' : 'GET', [], [], [], [], $json === null ? '' : (string) json_encode($json));
        if ($token !== null) {
            $request->headers->set(ChatAccess::TOKEN_HEADER, $token === 'valid' ? ChatAccess::generateToken() : $token);
        }
        $controller->setRequest($request);
        $this->app()->make(EntityManagerInterface::class)->clear();
        CacheLocal::flush();

        $response = $controller->$action();
        $raw = (string) $response->getContent();

        return [$response->getStatusCode(), json_decode($raw, true) ?? [], $raw];
    }

    /**
     * A turn that asks to set the throwaway page's description, as the superuser: it pauses.
     *
     * @return array{int, string} the page ID and the call ID
     */
    private function pausedTurn(): array
    {
        $page = $this->pages->create('Approval endpoint');
        $cID = (int) $page->getCollectionID();
        $callId = 'call_' . bin2hex(random_bytes(4));
        $controller = $this->controller(new FakeAIProvider(new ToolCallMessage(null, [new ToolCall('update_page', $callId, ['pageID' => $cID, 'description' => 'From the endpoint'])])));

        [$status, $body, $raw] = $this->call($controller, 'send_message', ['message' => 'Set the description.']);
        $this->assertSame(200, $status, $raw);
        $this->assertSame(ConcreteCmsAgent::AWAITING_REPLY, $body['response']);
        $this->assertTrue($body['awaitingApproval']);
        $this->assertCount(1, $body['approvals']);
        $this->assertSame($callId, $body['approvals'][0]['callId']);
        $this->assertSame(ApprovalService::PENDING, $body['approvals'][0]['status']);

        return [$cID, $callId];
    }

    public function testApproveRunsTheCallAndASecondDecisionIsRefused(): void
    {
        [$cID, $callId] = $this->pausedTurn();

        // A reload shows the waiting card.
        [, $current] = $this->call($this->controller(new FakeAIProvider()), 'current');
        $this->assertSame($callId, $current['chat']['approvals'][0]['callId'] ?? null);

        [$status, $body, $raw] = $this->call($this->controller(new FakeAIProvider(new AssistantMessage('Done.'))), 'decide', ['callId' => $callId, 'decision' => 'approve']);
        $this->assertSame(200, $status, $raw);
        $this->assertSame('Done.', $body['response']);
        $this->assertFalse($body['awaitingApproval']);
        $this->assertSame([], $body['approvals']);
        $this->assertSame(ApprovalService::APPROVED, $body['settledApprovals'][0]['status']);
        $this->assertSame('Approved', $body['settledApprovals'][0]['statusText']);
        $this->assertStringStartsWith('Changed: Approval endpoint', (string) $body['changesLine']);
        CacheLocal::flush();
        $this->assertSame('From the endpoint', (string) Page::getByID($cID, 'RECENT')->getCollectionDescription());

        [$status, $body] = $this->call($this->controller(new FakeAIProvider(new AssistantMessage('Unreachable.'))), 'decide', ['callId' => $callId, 'decision' => 'deny']);
        $this->assertSame(409, $status);
        $this->assertSame('not_waiting', $body['code']);

        // The reply keeps its "Changed" line after a reload.
        [, $current] = $this->call($this->controller(new FakeAIProvider()), 'current');
        $history = json_decode((string) $current['chat']['chatHistory'], true);
        $this->assertStringStartsWith('Changed: Approval endpoint', (string) (end($history)['changes'] ?? ''));
    }

    public function testDecideNeedsTheTokenTheChatsOwnerAndAValidDecision(): void
    {
        [, $callId] = $this->pausedTurn();
        $never = new FakeAIProvider(new AssistantMessage('Unreachable.'));

        [$status] = $this->call($this->controller($never), 'decide', ['callId' => $callId, 'decision' => 'approve'], null);
        $this->assertSame(403, $status, 'no token');

        [$status] = $this->call($this->controller($never), 'decide', ['callId' => $callId, 'decision' => 'yes please']);
        $this->assertSame(400, $status, 'typed text is not a decision');

        // Another user in the same browser session: not their chat.
        ThrowawayPages::actAs('neuron_content_editor');
        [$status] = $this->call($this->controller($never), 'decide', ['callId' => $callId, 'decision' => 'approve']);
        $this->assertSame(404, $status, 'another user');

        $never->assertCallCount(0);
        ThrowawayPages::actAs('superuser');
        [, $current] = $this->call($this->controller(new FakeAIProvider()), 'current');
        $this->assertSame(ApprovalService::PENDING, $current['chat']['approvals'][0]['status'] ?? null, 'still waiting');
    }
}
