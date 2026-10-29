<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\PageContextResolver;
use Katalysis\NeuronAi\Tests\Eval\PanelPath;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

/**
 * P3.11-2: the scenario runner's panel path (PanelPath). A turn goes through the chat endpoint (controllers/chat.php,
 * send_message) with the panel's own payload, and an approval through the decide endpoint, in process, as the browser
 * sends them: the token header, the session's chat, the page context the panel builds (CCM_CID, location, title, URL).
 * In edit mode the panel's URL is index.php?cID=…, so the page is found by its ID. No AI provider call: FakeAIProvider.
 */
final class PanelPathTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    /** @var int[] */
    private array $chatIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        foreach ($this->chatIds as $id) {
            (new ConcreteCmsAgent())->setChatId($id)->discardRun();
            $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $this->chatIds = [];
        $this->pages->restore();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function newChat(): string
    {
        $chat = new Chat();
        $sessionId = 'neuron-eval-p3112-' . bin2hex(random_bytes(4));
        $chat->setSessionId($sessionId);
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(USER_SUPER_ID);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        return $sessionId;
    }

    public function testATurnAndItsApprovalGoThroughTheEndpointsWithThePanelsPayload(): void
    {
        $page = $this->pages->create('Panel path');
        $cID = (int) $page->getCollectionID();
        $sessionId = $this->newChat();
        $call = new ToolCall('set_page_attribute_value', 'call_' . bin2hex(random_bytes(4)), ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Through the panel']);
        // One provider for every agent the endpoints make, as one conversation.
        $provider = new FakeAIProvider(new ToolCallMessage(null, [$call]), new AssistantMessage('Set, in a draft.'));
        $panel = new PanelPath(static fn (): ConcreteCmsAgent => (new ConcreteCmsAgent())->setAiProvider($provider), $sessionId);

        $context = PanelPath::pageContext($page, false);
        $this->assertSame($cID, $context['id'], 'CCM_CID');
        $sent = $panel->send('Set the meta title.', $context);

        $this->assertTrue($sent['awaitingApproval'], 'the turn waits for the card');
        $this->assertSame((string) $call->getCallId(), (string) $sent['approvals'][0]['callId']);

        $decided = $panel->decide((string) $call->getCallId(), 'approve', $context);
        $this->assertSame('Set, in a draft.', $decided['response']);
        $this->assertFalse($decided['awaitingApproval']);
        CacheLocal::flush();
        $this->assertSame('Through the panel', (string) Page::getByID($cID, 'RECENT')->getAttribute('meta_title'));
        $this->assertSame(['send_message', 'decide'], $panel->endpoints());
    }

    public function testInEditModeThePanelsUrlNamesThePageByItsIdAndThePageIsCheckedOut(): void
    {
        $page = $this->pages->create('Panel edit mode');
        $cID = (int) $page->getCollectionID();

        $context = PanelPath::pageContext($page, true);

        $this->assertSame('/index.php', $context['path'], 'edit mode\'s address');
        $this->assertStringContainsString('cID=' . $cID, $context['url']);
        $resolved = (new PageContextResolver())->fromBrowser(['path' => $context['path'], 'url' => $context['url']] + ['id' => null]);
        $this->assertSame($cID, $resolved['id'], 'found by the cID in the URL, without CCM_CID');

        PanelPath::checkOut($page, USER_SUPER_ID);
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT cIsCheckedOut FROM Pages WHERE cID = ?', [$cID]));
        PanelPath::checkIn($page);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT cIsCheckedOut FROM Pages WHERE cID = ?', [$cID]));
    }
}
