<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\VersionsToolkit;

/**
 * BF-12 (B-TRU-9): create_page_draft's "Preview URL" is an absolute URL, Concrete's own link to the page plus the
 * version, so the model never adds a host to a path. It was the page path plus "?cvID=N" (only "?cvID=N" on the home
 * page). The home page itself isn't used: a draft of the site's real home page is not the tests' to make. Throwaway
 * pages; the tool runs as the superuser; no AI provider call.
 */
final class DraftPreviewUrlTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->app()->make(Connection::class));
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
    }

    public function testThePreviewUrlIsAbsolute(): void
    {
        $page = $this->pages->create('Draft preview URL');
        $cID = (int) $page->getCollectionID();

        $output = ThrowawayPages::runTool(new VersionsToolkit(), 'create_page_draft', ['pageID' => $cID]);

        $this->assertMatchesRegularExpression('/\*\*New Version ID:\*\* (\d+)/', $output);
        preg_match('/\*\*New Version ID:\*\* (\d+)/', $output, $m);
        $link = (string) Page::getByID($cID)->getCollectionLink();
        $this->assertMatchesRegularExpression('~^https?://[^/]+/~', $link, 'the dev site has a canonical URL');

        $this->assertStringContainsString('**Preview URL:** `' . $link . '?cvID=' . $m[1] . '`', $output);
    }
}
