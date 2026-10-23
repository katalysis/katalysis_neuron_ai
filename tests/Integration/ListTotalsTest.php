<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\File\FileList;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Permission\Access\Access as PermissionAccess;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Permission\Key\PageKey;
use Concrete\Core\Tree\Node\Type\Topic as TopicNode;
use Concrete\Core\Tree\Type\Topic as TopicTree;
use Concrete\Core\User\UserList;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\KatalysisPro;
use Katalysis\NeuronAi\Tests\Support\PermissionCleanup;
use Katalysis\NeuronAi\Tests\Support\TaskKeyFixture;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\PeopleToolkit;
use Katalysis\NeuronAi\Tools\TopicsToolkit;
use Katalysis\NeuronAi\Tools\UsersToolkit;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * BF-6: every capped list says when there are more (B-TRU-3). Each list reports the true total of what the user may
 * see, takes an offset, and says how to get the next items; get_topic_tree_structure says where max_depth cut the tree.
 *
 * Each test works out the expected total itself, as the superuser, from the site as it is (no fixed state is assumed),
 * and asks for one item at a time, so it needs only two. A list with fewer than two items on this site is skipped with
 * the reason. Throwaway pages, a throwaway user and a throwaway topic tree are removed again; no AI provider call.
 */
final class ListTotalsTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    private ?TaskKeyFixture $fixture = null;

    /** @var list<int> view_page accesses this test created */
    private array $accesses = [];

    /** @var list<int> page IDs whose permissions this test overrode */
    private array $overridden = [];

    private ?TopicTree $tree = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (KatalysisPro::isInstalled()) {
            // katalysis_pro's places and reviews are Doctrine entities; the PHPUnit boot doesn't run package on_start().
            $service = ConcreteBoot::boot()->make(PackageService::class);
            $service->bootPackageEntityManager($service->getClass('katalysis_pro'));
        }
    }

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        ThrowawayPages::actAs('superuser');
        if ($this->tree !== null) {
            $rootID = (int) $this->tree->getRootTreeNodeID();
            $paIDs = array_map('intval', $this->db()->fetchFirstColumn('SELECT paID FROM TreeNodePermissionAssignments WHERE treeNodeID = ?', [$rootID]));
            $this->tree->delete();
            $this->db()->executeStatement('DELETE FROM TreeNodePermissionAssignments WHERE treeNodeID = ?', [$rootID]);
            foreach ($paIDs as $paID) {
                PermissionCleanup::deleteAccessIfUnused($this->db(), $paID);
            }
            $this->tree = null;
        }
        foreach ($this->overridden as $cID) {
            $this->db()->executeStatement('DELETE FROM AreaPermissionAssignments WHERE cID = ?', [$cID]);
        }
        $this->overridden = [];
        $this->pages->restore();
        foreach ($this->accesses as $paID) {
            PermissionCleanup::deleteAccessIfUnused($this->db(), $paID);
        }
        $this->accesses = [];
        $this->fixture?->restore();
        $this->fixture = null;
        $this->app()->make('cache/request')->flush();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $output): array
    {
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, $output);

        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    }

    /**
     * Calls a JSON list tool three times (the first item, the second, and an offset past the end) and checks the
     * window, the total and the note each time.
     *
     * @param array<string, mixed> $inputs
     */
    private function assertJsonWindows(ToolkitInterface $toolkit, string $tool, array $inputs, string $itemsKey, int $total, string $idKey): void
    {
        if ($total < 2) {
            $this->markTestSkipped("{$tool} needs at least two items on this site; it has {$total}.");
        }

        $first = $this->json(ThrowawayPages::runTool($toolkit, $tool, $inputs + ['limit' => 1]));
        $this->assertArrayHasKey($itemsKey, $first, $tool . ': ' . json_encode($first));
        $this->assertCount(1, $first[$itemsKey], "{$tool} returned more than the limit");
        $this->assertSame($total, $first['total'] ?? null, "{$tool}'s total is every item the user may see");
        $this->assertSame(0, $first['offset'] ?? null);
        $this->assertSame(1, $first['returned'] ?? null);
        $this->assertTrue($first['more'] ?? null);
        $this->assertStringContainsString("Showing 1–1 of {$total}", (string) ($first['note'] ?? ''));
        $this->assertStringContainsString("call {$tool} again with offset 1", (string) ($first['note'] ?? ''));

        $second = $this->json(ThrowawayPages::runTool($toolkit, $tool, $inputs + ['limit' => 1, 'offset' => 1]));
        $this->assertCount(1, $second[$itemsKey]);
        $this->assertSame($total, $second['total']);
        $this->assertSame(1, $second['offset']);
        $this->assertNotSame($first[$itemsKey][0][$idKey], $second[$itemsKey][0][$idKey], "{$tool}'s offset 1 is the next item");

        $past = $this->json(ThrowawayPages::runTool($toolkit, $tool, $inputs + ['limit' => 1, 'offset' => $total]));
        $this->assertSame([], $past[$itemsKey]);
        $this->assertFalse($past['more']);
        $this->assertStringContainsString("offset {$total} is past the end: there are {$total}", (string) ($past['note'] ?? ''));
    }

    /**
     * @return list<int> the IDs in the first column of a Markdown table
     */
    private static function tableIDs(string $output): array
    {
        preg_match_all('/^\| (\d+) \|/m', $output, $matches);

        return array_map('intval', $matches[1]);
    }

    private static function permitted(object $list): int
    {
        return count($list->getResults());
    }

    // ---- pages ----------------------------------------------------------------------------------------------

    public function testListPagesShowsTheLimitTheTotalAndTheNextOffset(): void
    {
        $parent = Page::getByPath('/neuron-test');
        $list = new PageList();
        $list->getQueryObject()->andWhere('cPointerExternalLink is null');
        $list->filterByParentID($parent->getCollectionID());
        $list->setPermissionsChecker(static fn ($page): bool => (bool) (new Checker($page))->canViewPage());

        $this->assertJsonWindows(new PagesToolkit(), 'list_pages', ['parentPath' => '/neuron-test'], 'pages', self::permitted($list), 'id');
    }

    public function testGetPageChildrenShowsTheLimitTheTotalAndTheNextOffset(): void
    {
        $parent = Page::getByPath('/neuron-test');
        $list = new PageList();
        $list->filterByParentID($parent->getCollectionID());
        $list->setPermissionsChecker(static fn ($page): bool => (bool) (new Checker($page))->canViewPage());

        $this->assertJsonWindows(new PagesToolkit(), 'get_page_children', ['parentPath' => '/neuron-test'], 'children', self::permitted($list), 'id');
    }

    // ---- files and users ------------------------------------------------------------------------------------

    public function testListFilesShowsTheLimitTheTotalAndTheNextOffset(): void
    {
        $list = new FileList();
        $list->setPermissionsChecker(static fn ($file): bool => (bool) (new Checker($file))->canViewFileInFileManager());

        $this->assertJsonWindows(new FilesToolkit(), 'list_files', [], 'files', self::permitted($list), 'id');
    }

    public function testListUsersShowsTheLimitTheTotalAndTheNextOffset(): void
    {
        $list = new UserList();
        $list->setPermissionsChecker(static fn ($user): bool => (bool) (new Checker($user))->canViewUser());

        $this->assertJsonWindows(new UsersToolkit(), 'list_users', [], 'users', self::permitted($list), 'id');
    }

    public function testSearchUsersReportsTheTrueTotal(): void
    {
        $list = new UserList();
        $list->filterByKeywords('neuron_');

        $this->assertJsonWindows(new UsersToolkit(), 'search_users', ['query' => 'neuron_'], 'users', self::permitted($list), 'id');
    }

    // ---- blocks ---------------------------------------------------------------------------------------------

    private function contentBlockTotal(): int
    {
        return (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM Blocks b
             INNER JOIN CollectionVersionBlocks cvb ON b.bID = cvb.bID
             INNER JOIN Pages p ON cvb.cID = p.cID
             INNER JOIN CollectionVersions cv ON cvb.cID = cv.cID AND cvb.cvID = cv.cvID
             WHERE b.btID = ? AND cvb.cvID = (SELECT MAX(cvn.cvID) FROM CollectionVersions cvn WHERE cvn.cID = cvb.cID)',
            [BlockType::getByHandle('content')->getBlockTypeID()]
        );
    }

    public function testSearchBlocksByTypeReportsTheTrueTotalAndTakesAnOffset(): void
    {
        $total = $this->contentBlockTotal();
        if ($total < 2) {
            $this->markTestSkipped("search_blocks_by_type needs at least two Content blocks; the site has {$total}.");
        }
        $blocks = new BlocksToolkit();

        $first = ThrowawayPages::runTool($blocks, 'search_blocks_by_type', ['blockTypeHandle' => 'content', 'limit' => 1]);
        $this->assertCount(1, self::tableIDs($first), $first);
        $this->assertStringContainsString("Found {$total} instance(s)", $first, 'the count is the total, not the rows shown');
        $this->assertStringContainsString("Showing 1–1 of {$total}", $first);
        $this->assertStringContainsString('call search_blocks_by_type again with offset 1', $first);

        $second = ThrowawayPages::runTool($blocks, 'search_blocks_by_type', ['blockTypeHandle' => 'content', 'limit' => 1, 'offset' => 1]);
        $this->assertCount(1, self::tableIDs($second), $second);
        $this->assertNotSame(self::tableIDs($first), self::tableIDs($second), 'offset 1 is the next block');

        $past = ThrowawayPages::runTool($blocks, 'search_blocks_by_type', ['blockTypeHandle' => 'content', 'limit' => 1, 'offset' => $total]);
        $this->assertSame([], self::tableIDs($past), $past);
        $this->assertStringContainsString("offset {$total} is past the end: there are {$total}", $past);
    }

    /**
     * Only the superuser can view the page (its view_page access lists no one).
     */
    private function hideFromEveryone(Page $page): void
    {
        $page->setPermissionsToManualOverride();
        $this->overridden[] = (int) $page->getCollectionID();
        $key = PageKey::getByHandle('view_page');
        $key->setPermissionObject($page);
        $access = PermissionAccess::create($key);
        $this->accesses[] = (int) $access->getPermissionAccessID();
        $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
        $this->app()->make('cache/request')->flush();
        CacheLocal::flush();
    }

    public function testSearchBlocksByTypeNeitherListsNorCountsBlocksOnPagesTheUserCannotView(): void
    {
        $page = $this->pages->create('Hidden from the reader');
        $hidden = $this->pages->blockIDs((int) $page->getCollectionID());
        $this->hideFromEveryone($page);
        $blocks = new BlocksToolkit();

        $asSuperuser = ThrowawayPages::runTool($blocks, 'search_blocks_by_type', ['blockTypeHandle' => 'content', 'limit' => 500]);
        foreach ($hidden as $bID) {
            $this->assertContains($bID, self::tableIDs($asSuperuser), 'the superuser sees the hidden page\'s blocks');
        }
        $this->assertMatchesRegularExpression('/Found (\d+) instance/', $asSuperuser);
        preg_match('/Found (\d+) instance/', $asSuperuser, $all);

        $this->fixture = new TaskKeyFixture($this->app());
        $reader = $this->fixture->createUser('bf6-reader');
        ThrowawayPages::actAs($reader->getUserName());
        $this->assertFalse((bool) (new Checker(Page::getByID($page->getCollectionID())))->canViewPage(), 'the reader must not view the hidden page');

        $asReader = ThrowawayPages::runTool($blocks, 'search_blocks_by_type', ['blockTypeHandle' => 'content', 'limit' => 500]);
        foreach ($hidden as $bID) {
            $this->assertNotContains($bID, self::tableIDs($asReader), "block {$bID} is on a page the reader can't view");
        }
        $this->assertMatchesRegularExpression('/Found (\d+) instance/', $asReader);
        preg_match('/Found (\d+) instance/', $asReader, $seen);
        $this->assertLessThanOrEqual((int) $all[1] - count($hidden), (int) $seen[1], 'the hidden blocks are not counted');
    }

    // ---- versions -------------------------------------------------------------------------------------------

    public function testListPageVersionsReportsTheTotalAndTakesAnOffset(): void
    {
        $cID = (int) $this->pages->create('Two versions')->getCollectionID();
        Page::getByID($cID)->getVersionToModify();
        CacheLocal::flush();
        $total = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$cID]);
        $this->assertGreaterThanOrEqual(2, $total);
        $versions = new VersionsToolkit();

        $first = ThrowawayPages::runTool($versions, 'list_page_versions', ['pageID' => $cID, 'limit' => 1]);
        $this->assertCount(1, self::tableIDs($first), $first);
        $this->assertStringContainsString("Showing 1–1 of {$total}", $first);
        $this->assertStringContainsString('call list_page_versions again with offset 1', $first);

        $second = ThrowawayPages::runTool($versions, 'list_page_versions', ['pageID' => $cID, 'limit' => 1, 'offset' => 1]);
        $this->assertCount(1, self::tableIDs($second), $second);
        $this->assertNotSame(self::tableIDs($first), self::tableIDs($second));

        $past = ThrowawayPages::runTool($versions, 'list_page_versions', ['pageID' => $cID, 'limit' => 1, 'offset' => $total]);
        $this->assertSame([], self::tableIDs($past), $past);
        $this->assertStringContainsString("offset {$total} is past the end: there are {$total}", $past);
    }

    // ---- people ---------------------------------------------------------------------------------------------

    public function testCoreListPeopleReportsTheTrueTotal(): void
    {
        if (!KatalysisPro::isInstalled()) {
            $this->markTestSkipped('The core list_people reads katalysis_pro\'s people; katalysis_pro is not installed.');
        }
        $total = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM KatalysisPeople WHERE active = 1');

        $this->assertJsonWindows(new PeopleToolkit(), 'list_people', [], 'people', $total, 'sID');
    }

    /**
     * katalysis_pro's three lists filter in PHP; the expected total is what the tool returns with the largest limit.
     *
     * @return array<string, array{string, array<string, mixed>, string, string}>
     */
    public static function katalysisProLists(): array
    {
        return [
            'list_people' => ['list_people', ['search' => 'Test Person'], 'people', 'sID'],
            'list_places' => ['list_places', [], 'places', 'sID'],
            'list_reviews' => ['list_reviews', [], 'reviews', 'sID'],
        ];
    }

    /**
     * @param array<string, mixed> $inputs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('katalysisProLists')]
    public function testKatalysisProListsReportTheTrueTotal(string $tool, array $inputs, string $itemsKey, string $idKey): void
    {
        if (!KatalysisPro::isInstalled()) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }
        $class = KatalysisPro::TOOLKIT;
        $definition = null;
        foreach ((new $class())->definitions() as $candidate) {
            if ($candidate->name() === $tool) {
                $definition = $candidate;
            }
        }
        $this->assertNotNull($definition, "katalysis_pro has no {$tool}");
        $run = function (array $with) use ($definition): array {
            $neuronTool = $definition->toNeuronTool();
            try {
                $neuronTool->setInputs($with)->execute();
            } catch (\Throwable $e) {
                $this->fail("{$neuronTool->getName()}: " . $e->getMessage());
            }

            return $this->json((string) $neuronTool->getResult());
        };

        $everything = $run($inputs + ['limit' => 100]);
        $this->assertArrayHasKey($itemsKey, $everything, json_encode($everything));
        $total = count($everything[$itemsKey]);
        $this->assertLessThan(100, $total, 'the expected total must fit in one call');
        if ($total < 2) {
            $this->markTestSkipped("{$tool} needs at least two items on this site; it has {$total}.");
        }

        $first = $run($inputs + ['limit' => 1]);
        $this->assertCount(1, $first[$itemsKey]);
        $this->assertSame(1, $first['count'], 'count stays the number of items in this result');
        $this->assertSame($total, $first['total'] ?? null, "{$tool}'s total is every match");
        $this->assertTrue($first['more'] ?? null);
        $this->assertStringContainsString("call {$tool} again with offset 1", (string) ($first['note'] ?? ''));

        $second = $run($inputs + ['limit' => 1, 'offset' => 1]);
        $this->assertCount(1, $second[$itemsKey]);
        $this->assertNotSame($first[$itemsKey][0][$idKey], $second[$itemsKey][0][$idKey]);

        $past = $run($inputs + ['limit' => 1, 'offset' => $total]);
        $this->assertSame([], $past[$itemsKey]);
        $this->assertStringContainsString("offset {$total} is past the end: there are {$total}", (string) ($past['note'] ?? ''));
    }

    // ---- the topic tree -------------------------------------------------------------------------------------

    public function testTheTopicTreeSaysWhereMaxDepthCutIt(): void
    {
        $this->tree = TopicTree::add('BF-6 test tree ' . bin2hex(random_bytes(3)));
        $root = $this->tree->getRootTreeNodeObject();
        $branch = TopicNode::add('Branch', $root);
        $twig = TopicNode::add('Twig', $branch);
        TopicNode::add('Leaf on the twig', $twig);
        TopicNode::add('Leaf', $root);
        $topics = new TopicsToolkit();

        $cut = $this->json(ThrowawayPages::runTool($topics, 'get_topic_tree_structure', ['tree_name' => $this->tree->getTreeName(), 'max_depth' => 1]));
        $byName = array_column($cut['topics'], null, 'name');
        $this->assertTrue($byName['Branch']['hasChildren'], 'a node whose children were cut still has children');
        $this->assertSame([], $byName['Branch']['children']);
        $this->assertTrue($byName['Branch']['childrenNotShown'] ?? null);
        $this->assertFalse($byName['Leaf']['hasChildren']);
        $this->assertFalse($byName['Leaf']['childrenNotShown'] ?? false);
        $this->assertTrue($cut['cutAtDepth'] ?? null);
        $this->assertStringContainsString('Cut at depth 1', (string) ($cut['note'] ?? ''));
        $this->assertStringContainsString('1 topic(s) have children not shown', (string) ($cut['note'] ?? ''));
        $this->assertStringContainsString('higher max_depth', (string) ($cut['note'] ?? ''));

        $whole = $this->json(ThrowawayPages::runTool($topics, 'get_topic_tree_structure', ['tree_name' => $this->tree->getTreeName(), 'max_depth' => 10]));
        $this->assertFalse($whole['cutAtDepth'] ?? null);
        $this->assertArrayNotHasKey('note', $whole);
        $this->assertSame(4, $whole['totalTopics']);
    }
}
