<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\PageContextResolver;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;

/**
 * BF-1: the page the user is on, from what the chat panel sends (plans/BF-1.md 3.1). Before BF-1 this failed on the
 * home page (its path is null, so a lookup of "/" finds nothing), on dashboard pages (their URLs start with
 * /index.php) and in edit mode, because the panel never found a page ID on this theme.
 *
 * The ID from the browser is untrusted: a page the user can't view is treated as unresolved and logged. The page the
 * seeded neuron_editor can't view is /dashboard/system. Read-only: nothing is written.
 */
final class PageContextResolverTest extends ConcreteTestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        $this->app()->make('cache/request')->flush();
    }

    private function resolver(): PageContextResolver
    {
        $this->logged = [];

        return new PageContextResolver(function (string $level, string $message): void {
            $this->logged[] = $level . ': ' . $message;
        });
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
        // Permission answers are cached for the request.
        $this->app()->make('cache/request')->flush();
    }

    private static function idOf(string $path): int
    {
        $page = Page::getByPath($path);
        if (!$page || $page->isError()) {
            self::fail("{$path} does not exist. Run the seed script first.");
        }

        return (int) $page->getCollectionID();
    }

    private function homeID(): int
    {
        return (int) $this->app()->make('site')->getSite()->getSiteHomePageID();
    }

    public function testTheHomePageResolvesFromSlash(): void
    {
        $this->actAs('neuron_editor');

        $context = $this->resolver()->fromBrowser(['path' => '/', 'title' => 'Home', 'url' => 'http://katalysis-pro.test/']);

        $this->assertSame($this->homeID(), $context['id']);
        $this->assertSame('/', $context['path']);
        $this->assertSame('Home', $context['title']);
        $this->assertSame([], $this->logged);
    }

    public function testADashboardUrlWithIndexPhpResolvesForAUserWhoCanViewIt(): void
    {
        $this->actAs('superuser');

        $context = $this->resolver()->fromBrowser(['path' => '/index.php/dashboard/system']);

        $this->assertSame(self::idOf('/dashboard/system'), $context['id']);
        $this->assertSame('/dashboard/system', $context['path']);
    }

    public function testADashboardPageTheUserCannotViewIsUnresolvedAndLogged(): void
    {
        $this->actAs('neuron_editor');
        $this->assertFalse((bool) (new Checker(Page::getByPath("/dashboard/system")))->canViewPage(), 'neuron_editor should not see the dashboard');

        $context = $this->resolver()->fromBrowser(['path' => '/index.php/dashboard/system']);

        $this->assertNull($context['id']);
        $this->assertNull($context['path']);
        $this->assertCount(1, $this->logged);
        $this->assertStringStartsWith('warning: ', $this->logged[0]);
        $this->assertStringContainsString('not viewable', $this->logged[0]);
    }

    public function testAnIdWithNoPathResolves(): void
    {
        $this->actAs('neuron_editor');
        $headings = self::idOf('/neuron-test/headings');

        $context = $this->resolver()->fromBrowser(['id' => $headings]);

        $this->assertSame($headings, $context['id']);
        $this->assertSame('/neuron-test/headings', $context['path']);
    }

    public function testAnIdTheUserCannotViewIsIgnoredAndLoggedAndThePathIsUsed(): void
    {
        $this->actAs('neuron_editor');

        $context = $this->resolver()->fromBrowser(['id' => self::idOf('/dashboard/system'), 'path' => '/neuron-test/headings']);

        $this->assertSame(self::idOf('/neuron-test/headings'), $context['id']);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('not viewable', $this->logged[0]);
    }

    public function testAnIdTheUserCannotViewWithNoPathIsUnresolved(): void
    {
        $this->actAs('neuron_editor');

        $context = $this->resolver()->fromBrowser(['id' => self::idOf('/dashboard/system')]);

        $this->assertNull($context['id']);
        $this->assertNull($context['path']);
        $this->assertCount(1, $this->logged);
    }

    public function testTheIdWinsOverAPathToAnotherPage(): void
    {
        $this->actAs('neuron_editor');

        $context = $this->resolver()->fromBrowser(['id' => self::idOf('/neuron-test/headings'), 'path' => '/neuron-test/columns']);

        $this->assertSame(self::idOf('/neuron-test/headings'), $context['id']);
    }

    public function testAFrontEndPageInEditModeResolvesFromItsIdOrTheCidInItsUrl(): void
    {
        $this->actAs('neuron_editor');
        $headings = self::idOf('/neuron-test/headings');
        $url = "http://katalysis-pro.test/index.php?cID={$headings}&ctask=check-out-first";

        $withId = $this->resolver()->fromBrowser(['id' => $headings, 'path' => '/index.php', 'url' => $url]);
        $fromUrl = $this->resolver()->fromBrowser(['path' => '/index.php', 'url' => $url]);

        $this->assertSame($headings, $withId['id']);
        $this->assertSame($headings, $fromUrl['id']);
        $this->assertSame('/neuron-test/headings', $fromUrl['path']);
    }

    public function testANonexistentIdIsUnresolvedWithoutAnError(): void
    {
        $this->actAs('neuron_editor');

        $context = $this->resolver()->fromBrowser(['id' => 999999999]);

        $this->assertNull($context['id']);
        $this->assertNull($context['path']);
    }

    public function testTheReferrerIsUsedWhenThePanelSendsNoPath(): void
    {
        $this->actAs('neuron_editor');

        $context = $this->resolver()->fromBrowser([], '/neuron-test/headings');

        $this->assertSame(self::idOf('/neuron-test/headings'), $context['id']);
    }

    public function testIndexPhpIsStrippedOnlyAsALeadingSegment(): void
    {
        $this->assertSame('/dashboard/system', PageContextResolver::normalizePath('/index.php/dashboard/system'));
        $this->assertSame('/', PageContextResolver::normalizePath('/index.php'));
        $this->assertSame('/', PageContextResolver::normalizePath('/index.php/'));
        $this->assertSame('/foo/index.php', PageContextResolver::normalizePath('/foo/index.php'));
        $this->assertSame('/index.phpx/a', PageContextResolver::normalizePath('/index.phpx/a'));
        $this->assertSame('/neuron-test/headings', PageContextResolver::normalizePath('neuron-test//headings/'));
        $this->assertNull(PageContextResolver::normalizePath(''));
    }

    public function testPageForPathFindsTheHomePageAndDashboardPages(): void
    {
        $this->assertSame($this->homeID(), (int) PageContextResolver::pageForPath('/')?->getCollectionID());
        $this->assertSame(self::idOf('/dashboard/system'), (int) PageContextResolver::pageForPath('/index.php/dashboard/system')?->getCollectionID());
        $this->assertNull(PageContextResolver::pageForPath('/no-such-page-bf1'));
    }
}
