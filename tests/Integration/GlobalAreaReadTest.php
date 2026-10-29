<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\GlobalAreaFixture;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;

/**
 * P3.6a (Glyn, on approving the plan): the read tools show a global area's blocks, from its stack, marked as shared, so
 * the model can see what it would change through the page. Before, get_page_content read only the page's own blocks.
 * A throwaway global area shown on two pages, as the superuser.
 */
final class GlobalAreaReadTest extends ConcreteTestCase
{
    private GlobalAreaFixture $fixture;

    protected function setUp(): void
    {
        ThrowawayPages::actAs('superuser');
        $this->fixture = new GlobalAreaFixture($this->app()->make(Connection::class));
    }

    protected function tearDown(): void
    {
        ThrowawayPages::actAs('superuser');
        $this->fixture->restore();
        Request::getInstance()->setCustomRequestUser(-1);
    }

    public function testGetPageContentListsTheGlobalAreasBlocksAsShared(): void
    {
        [$handle, , $bID] = $this->fixture->globalArea('<p>Shared banner text</p>');
        $page = $this->fixture->page([$handle]);
        $this->fixture->page([$handle]);

        $output = ToolByName::run('get_page_content', ['pageID' => (int) $page->getCollectionID()]);

        $this->assertStringContainsString('Shared banner text', $output);
        $data = json_decode($output, true);
        $blocks = $data['data']['blocks'] ?? $data['blocks'] ?? [];
        $entry = array_values(array_filter($blocks, static fn (array $b): bool => (int) $b['blockID'] === $bID))[0] ?? null;
        $this->assertNotNull($entry, "the global area's block is listed: {$output}");
        $this->assertSame($handle, $entry['area']);
        $this->assertSame(['name' => $handle, 'pagesShowingIt' => 2], $entry['globalArea']);
    }

    public function testGetBlockDataReadsAGlobalAreasBlockThroughThePage(): void
    {
        [$handle, , $bID] = $this->fixture->globalArea('<p>Shared banner text</p>');
        $page = $this->fixture->page([$handle]);

        $output = ToolByName::run('get_block_data', ['blockID' => $bID, 'pageID' => (int) $page->getCollectionID()]);

        $this->assertStringContainsString('Shared banner text', $output);
        $this->assertStringContainsString('**Area:** ' . $handle . ' (a global area, shared by every page that shows it: 1 page;', $output);
        $this->assertStringNotContainsString('not found', $output);
    }
}
