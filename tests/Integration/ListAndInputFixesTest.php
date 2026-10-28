<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Permission\Checker;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\Utilities\PermittedWindow;

/**
 * P3.3-5: the list and input fixes.
 *
 * - list_pages and get_page_children declare onlyVisible (pages shown in navigation), which the prompt named but the
 *   tools refused or lacked (BF-6)
 * - get_page_children takes parentID as well as pageID (scenario 17)
 * - large sites: a list loads and permission-checks matches only until its window is full and PermittedWindow's check
 *   limit is reached; past that it reports "at least" the count it reached, and that there may be more. The tests
 *   lower the limit (PermittedWindow::$checkLimit) rather than build a thousand pages.
 * - list_page_types counts only pages the user may view
 * - the batch tool's refusal of a missing blockID points to add_block_to_page_area (scenario 27 in P3.3-7)
 *
 * Throwaway pages under /neuron-test, deleted afterwards. No AI provider call.
 */
final class ListAndInputFixesTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
        $this->app()->make('cache/request')->flush();
        PermittedWindow::$checkLimit = PermittedWindow::CHECK_LIMIT;
    }

    // ---- onlyVisible, and parentID for get_page_children ------------------------------------------------------

    public function testOnlyVisibleLeavesOutPagesHiddenFromNavigation(): void
    {
        [$parentID, $children] = $this->parentWithChildren(3);
        Page::getByID($children[1])->setAttribute('exclude_nav', 1);

        $all = $this->json(ToolByName::run('list_pages', ['parentID' => $parentID]));
        $visible = $this->json(ToolByName::run('list_pages', ['parentID' => $parentID, 'onlyVisible' => true]));
        $this->assertSame(3, $all['total']);
        $this->assertSame(2, $visible['total']);
        $this->assertNotContains($children[1], array_column($visible['pages'], 'id'));

        $childrenVisible = $this->json(ToolByName::run('get_page_children', ['pageID' => $parentID, 'onlyVisible' => true]));
        $this->assertSame(2, $childrenVisible['total']);
        $this->assertNotContains($children[1], array_column($childrenVisible['children'], 'id'));
    }

    public function testGetPageChildrenTakesParentIDAsWellAsPageID(): void
    {
        [$parentID] = $this->parentWithChildren(2);

        // Scenario 17: the model sent parentID, as list_pages takes it, in 2 of 3 runs.
        $result = $this->json(ToolByName::run('get_page_children', ['parentID' => $parentID]));

        $this->assertSame(2, $result['total']);
        $this->assertSame($parentID, $result['parent']['id']);
    }

    /**
     * BF-20 (scenario 15 in P3.S2): list_pages with parentPath "/" and limit 1 returned /neuron-test, and the model took
     * it for the home page. The result names its parent page.
     */
    public function testListPagesNamesItsParentPage(): void
    {
        [$parentID] = $this->parentWithChildren(1);

        $byID = $this->json(ToolByName::run('list_pages', ['parentID' => $parentID]));
        $this->assertSame($parentID, $byID['parent']['id'] ?? null);
        $this->assertStringStartsWith('/neuron-test/', (string) ($byID['parent']['path'] ?? ''));
        $this->assertStringStartsWith('List parent', (string) ($byID['parent']['name'] ?? ''));

        $home = $this->json(ToolByName::run('list_pages', ['parentPath' => '/', 'limit' => 1]));
        $this->assertSame('/', $home['parent']['path'] ?? null, 'The home page is named as the parent');
        $this->assertSame((int) Page::getHomePageID(), $home['parent']['id'] ?? null);
    }

    // ---- large lists ------------------------------------------------------------------------------------------

    public function testAListStopsCountingAtTheCheckLimitAndSaysAtLeast(): void
    {
        [$parentID] = $this->parentWithChildren(8);
        PermittedWindow::$checkLimit = 5;

        foreach (['list_pages' => ['parentID' => $parentID, 'limit' => 2], 'get_page_children' => ['pageID' => $parentID, 'limit' => 2]] as $tool => $inputs) {
            $result = $this->json(ToolByName::run($tool, $inputs));
            $this->assertSame(2, $result['returned'], $tool);
            $this->assertSame(5, $result['total'], "{$tool}: the count it reached");
            $this->assertTrue($result['totalIsAtLeast'] ?? false, $tool);
            $this->assertTrue($result['more'], $tool);
            $this->assertStringContainsString('at least 5', (string) $result['note'], $tool);
        }

        // A window past the limit is still filled: the limit stops the counting, not the list.
        $later = $this->json(ToolByName::run('list_pages', ['parentID' => $parentID, 'limit' => 2, 'offset' => 6]));
        $this->assertSame(2, $later['returned']);
    }

    public function testWithinTheCheckLimitTheTotalIsExact(): void
    {
        [$parentID] = $this->parentWithChildren(4);

        $result = $this->json(ToolByName::run('list_pages', ['parentID' => $parentID, 'limit' => 2]));

        $this->assertSame(4, $result['total']);
        $this->assertArrayNotHasKey('totalIsAtLeast', $result);
    }

    public function testFilesAndUsersListsStopCountingToo(): void
    {
        PermittedWindow::$checkLimit = 1;

        foreach (['list_files', 'list_users'] as $tool) {
            $result = $this->json(ToolByName::run($tool, ['limit' => 1]));
            $this->assertSame(1, $result['returned'], $tool);
            $this->assertTrue($result['totalIsAtLeast'] ?? false, "{$tool} counted every match past the check limit");
        }
    }

    public function testCountBlocksByTypeSaysAtLeastPastTheCheckLimit(): void
    {
        $this->parentWithChildren(3);
        PermittedWindow::$checkLimit = 1;

        $output = ToolByName::run('count_blocks_by_type', ['blockTypeHandle' => 'content']);

        $this->assertStringContainsString('at least', $output);
    }

    public function testListPageTypesCountsOnlyPagesTheUserMayView(): void
    {
        PermittedWindow::$checkLimit = PHP_INT_MAX;
        ThrowawayPages::actAs('neuron_editor');

        $result = $this->json(ToolByName::run('list_page_types', []));
        $counts = array_column($result['pageTypes'], 'usageCount', 'handle');

        $list = new PageList();
        $list->filterByPageTypeHandle('page');
        $list->setPermissionsChecker(static fn (Page $p): bool => (bool) (new Checker($p))->canViewPage());
        $this->assertSame(count($list->getResults()), $counts['page'] ?? null);
    }

    // ---- inputs -----------------------------------------------------------------------------------------------

    public function testTheBatchToolPointsANewBlockToAddBlockToPageArea(): void
    {
        $cID = (int) $this->pages->create('Batch new block')->getCollectionID();

        // Scenario 27 in P3.3-7: a new block sent as a batch item with blockID 0.
        $output = ToolByName::run('batch_update_page_content', [
            'pageID' => $cID, 'blocks' => [['areaHandle' => 'Main', 'blockID' => 0, 'value' => ['content' => '<p>New.</p>']]],
        ]);

        $this->assertStringContainsString('add_block_to_page_area', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    public function testTheHeadingsToolNoLongerTakesIncludeInactive(): void
    {
        $names = array_map(static fn ($p): string => $p->getName(), ToolByName::tool('batch_update_content_headings')->getProperties());

        $this->assertNotContains('includeInactive', $names);
    }

    /**
     * BF-20 (scenario 24 in P3.S2): the model used the headings tool to rename a heading.
     */
    public function testTheHeadingsToolSaysItChangesLevelsOnly(): void
    {
        $description = ToolByName::tool('batch_update_content_headings')->getDescription();

        $this->assertStringContainsString('levels only', (string) $description);
        $this->assertStringContainsString('update_block_in_page_area', (string) $description);
    }

    public function testTheBlockWriteToolsSayWhereTheFieldNamesComeFrom(): void
    {
        foreach (['add_block_to_page_area', 'update_block_in_page_area'] as $tool) {
            $value = null;
            foreach (ToolByName::tool($tool)->getProperties() as $property) {
                if ($property->getName() === 'value') {
                    $value = $property->getDescription();
                }
            }
            $this->assertStringContainsString('get_block_data', (string) $value, $tool);
            $this->assertStringContainsString('content', (string) $value, "{$tool}: the html block's field (scenario 07 sent htmlContent)");
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    /**
     * @return array{0: int, 1: list<int>} a throwaway parent and its children's IDs
     */
    private function parentWithChildren(int $count): array
    {
        $parent = $this->pages->create('List parent');
        $template = PageTemplate::getByHandle('full');
        $children = [];
        for ($i = 1; $i <= $count; $i++) {
            $child = $parent->add(PageType::getByHandle('page'), [
                'cName' => "Child {$i}", 'cHandle' => 'child-' . $i, 'pTemplateID' => $template->getPageTemplateID(),
            ], $template);
            $children[] = (int) $child->getCollectionID();
        }

        return [(int) $parent->getCollectionID(), $children];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $output): array
    {
        $data = json_decode($output, true);
        $this->assertIsArray($data, "Not a JSON result:\n{$output}");

        return $data;
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
