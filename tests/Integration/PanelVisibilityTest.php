<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Http\ResponseAssetGroup;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\View\PageView;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller as PackageController;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\TaskKeyFixture;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * P1.10a: the chat panel goes into a page only when the user holds "Use Neuron AI assistant" AND Concrete shows
 * them the toolbar on that page (B-ACC-1). The panel opens only from a button in the toolbar, so without the
 * toolbar it could not be opened and its markup, CSS and JS are not sent.
 *
 * The toolbar test is Concrete's own: PageView::startRender() and page_controls_header.php ask the page's
 * permissions for canViewToolbar(). The tests compare against that same answer. Where the answer is "no panel" they
 * also run the real on_before_render listener (the one controller.php registers) and check that nothing is added.
 * Where it is "panel" they check the gate (ChatAccess::canShowPanelOn()); the listener's own output needs the whole
 * site booted (themes, packages), so that half is checked by hand under c5:exec, not here.
 *
 *  - neuron_chat_editor (use key, page rights on /neuron-test): panel on /neuron-test/headings, none on /;
 *  - neuron_editor (the toolbar, but no use key): no panel;
 *  - a user with the use key and no toolbar right anywhere: no panel;
 *  - the super user on a dashboard page: the toolbar is shown there, so the panel is too;
 *  - guests and views that are not pages: no panel.
 *
 * Needs the seeded, upgraded dev site. Nothing calls the AI provider.
 */
final class PanelVisibilityTest extends ConcreteTestCase
{
    private const CHAT_USER = 'neuron_chat_editor';
    private const NO_ACCESS_USER = 'neuron_editor';
    private const HEADINGS_PATH = '/neuron-test/headings';
    private const DASHBOARD_PAGE_PATH = '/dashboard/system/ai/neuron_ai';
    private const PANEL_SCRIPT = '/js/chat-panel.js';

    private ?TaskKeyFixture $fixture = null;

    protected function setUp(): void
    {
        $this->ensurePanelListenerIsRegistered();
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        if ($this->fixture !== null) {
            $this->fixture->restore();
            $this->fixture = null;
        }
        ResponseAssetGroup::get()->init();
        $this->app()->make('cache/request')->flush();
    }

    // ---- The panel for each user and page ----------------------------------

    public function testTheChatEditorGetsThePanelWhereConcreteShowsThemTheToolbar(): void
    {
        $this->actAs(self::CHAT_USER);
        $page = $this->page(self::HEADINGS_PATH);

        $this->assertTrue($this->toolbarShown($page), 'Precondition: the toolbar is shown to this user on the page.');
        $this->assertTrue(ChatAccess::canShowPanelOn(new PageView($page)));
    }

    public function testTheChatEditorGetsNoPanelOnTheHomePage(): void
    {
        $this->actAs(self::CHAT_USER);
        $home = $this->page('/');

        $this->assertTrue(ChatAccess::canUse(), 'Precondition: the user may use the assistant.');
        $this->assertFalse($this->toolbarShown($home), 'Precondition: Concrete shows no toolbar on / for this user.');
        $this->assertFalse(ChatAccess::canShowPanelOn(new PageView($home)));
        $this->assertFalse($this->listenerAddsThePanel($home), 'No panel markup, CSS or JS is sent.');
    }

    public function testTheSuperUserGetsThePanelOnADashboardPageBecauseTheToolbarIsShownThere(): void
    {
        $this->actAs('superuser');
        $page = $this->page(self::DASHBOARD_PAGE_PATH);

        $this->assertTrue($this->toolbarShown($page), 'Concrete shows the toolbar on dashboard pages to this user.');
        $this->assertTrue(ChatAccess::canShowPanelOn(new PageView($page)));
    }

    public function testTheSuperUserGetsThePanelOnTheHomePageToo(): void
    {
        $this->actAs('superuser');
        $home = $this->page('/');

        $this->assertTrue($this->toolbarShown($home));
        $this->assertTrue(ChatAccess::canShowPanelOn(new PageView($home)));
    }

    public function testTheToolbarWithoutTheUseKeyGivesNoPanel(): void
    {
        $this->actAs(self::NO_ACCESS_USER);
        $page = $this->page(self::HEADINGS_PATH);

        $this->assertTrue($this->toolbarShown($page), 'Precondition: this user does get the toolbar there.');
        $this->assertFalse(ChatAccess::canUse(), 'Precondition: this user has no use key.');
        $this->assertFalse(ChatAccess::canShowPanelOn(new PageView($page)));
        $this->assertFalse($this->listenerAddsThePanel($page));
    }

    public function testTheUseKeyWithoutAnyToolbarRightGivesNoPanelAnywhere(): void
    {
        $this->fixture = new TaskKeyFixture($this->app());
        $user = $this->fixture->createUser('toolbarless');
        $group = $this->fixture->createGroup();
        $this->fixture->addToGroup($user, $group);
        $this->fixture->grantToGroup('use_neuron_ai', $group);
        $this->actAs($user);

        $this->assertTrue(ChatAccess::canUse(), 'Precondition: the user holds the use key.');
        foreach (['/', self::HEADINGS_PATH] as $path) {
            $page = $this->page($path);
            $this->assertFalse($this->toolbarShown($page), "Precondition: no toolbar on {$path}.");
            $this->assertFalse(ChatAccess::canShowPanelOn(new PageView($page)), "No panel on {$path}.");
            $this->assertFalse($this->listenerAddsThePanel($page), "Nothing is added on {$path}.");
        }
    }

    public function testGuestsGetNoPanel(): void
    {
        $this->actAs('guest');
        $page = $this->page('/');

        $this->assertFalse(ChatAccess::canShowPanelOn(new PageView($page)));
        $this->assertFalse($this->listenerAddsThePanel($page));
    }

    public function testAViewThatIsNotAPageNeverGetsThePanel(): void
    {
        $this->actAs('superuser');

        $this->assertFalse(ChatAccess::canShowPanelOn(null));
        $this->assertFalse(ChatAccess::canShowPanelOn(new \stdClass()));
    }

    public function testTheEndpointsStillUseTheUseKeyAloneNotTheToolbar(): void
    {
        $this->actAs(self::CHAT_USER);
        $this->assertFalse($this->toolbarShown($this->page('/')), 'Precondition: no toolbar on /.');

        $this->assertNull(ChatAccess::refusalReason(false, null), 'A use-key holder is not refused because of the page.');
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * The listener is registered by the package's on_start(), which the test boot may not run. If it isn't there,
     * register the package's own listener (the private injectChatPanel() of its controller), so the tests exercise the
     * real thing and not a copy. If it is there already, nothing changes.
     */
    private function ensurePanelListenerIsRegistered(): void
    {
        require_once dirname(__DIR__, 2) . '/controller.php';

        $director = $this->app()->make('director');
        foreach ($director->getListeners('on_before_render') as $listener) {
            if ($listener instanceof \Closure
                && (new \ReflectionFunction($listener))->getClosureScopeClass()?->getName() === PackageController::class) {
                return;
            }
        }

        $method = new \ReflectionMethod(PackageController::class, 'injectChatPanel');
        $method->invoke(new PackageController($this->app()));
    }

    /**
     * @param \Concrete\Core\User\UserInfo|string $who 'guest', 'superuser', a seeded user name or a throwaway user
     */
    private function actAs(mixed $who): void
    {
        $request = Request::getInstance();
        if ($who === 'guest') {
            $request->setCustomRequestUser(-1);
        } elseif (is_string($who)) {
            $repository = $this->app()->make(UserInfoRepository::class);
            $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
            $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
            $request->setCustomRequestUser($info);
        } else {
            $request->setCustomRequestUser($who);
        }
        // Permission answers (canViewToolbar() among them) are cached for the request, whatever the page.
        $this->app()->make('cache/request')->flush();
    }

    private function page(string $path): Page
    {
        // getByPath('/') is an error page: the home page is found by its ID.
        $page = $path === '/' ? Page::getByID(Page::getHomePageID()) : Page::getByPath($path);
        $this->assertFalse($page->isError(), "The page {$path} is missing. Run the seed script first.");

        return $page;
    }

    /** What Concrete itself asks before it renders the toolbar. */
    private function toolbarShown(Page $page): bool
    {
        $this->app()->make('cache/request')->flush();

        return (bool) (new Checker($page))->canViewToolbar();
    }

    /**
     * Runs the real on_before_render listeners for a page view and reports whether the panel was added: its markup
     * (the element prints itself while the listener runs) and its script.
     */
    private function listenerAddsThePanel(Page $page): bool
    {
        $this->app()->make('cache/request')->flush();
        $assets = ResponseAssetGroup::get();
        $assets->init();

        $event = new GenericEvent();
        $event->setArgument('view', new PageView($page));
        ob_start();
        try {
            $this->app()->make('director')->dispatch('on_before_render', $event);
        } finally {
            $printed = (string) ob_get_clean();
        }

        $scriptAdded = false;
        foreach ($assets->getAssetsToOutput() as $position) {
            foreach ($position as $asset) {
                if (is_string($asset) && str_contains($asset, 'katalysis_neuron_ai' . self::PANEL_SCRIPT)) {
                    $scriptAdded = true;
                }
            }
        }
        $assets->init();
        $markupAdded = str_contains($printed, 'id="neuron-chat-panel"');

        $this->assertSame($markupAdded, $scriptAdded, 'The panel markup and its script are added together or not at all.');

        return $markupAdded && $scriptAdded;
    }
}
