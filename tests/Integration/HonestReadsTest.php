<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\ContainerArea;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\Page\Container;
use Concrete\Core\File\File;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Container\ContainerBlockInstance;
use Concrete\Core\Page\Page;
use Concrete\Core\Tree\Node\Type\Category as CategoryNode;
use Concrete\Core\Tree\Node\Type\Topic as TopicNode;
use Concrete\Core\Tree\Type\Topic as TopicTree;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\PermissionCleanup;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\TopicsToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;
use Katalysis\NeuronAi\Tools\Utilities\PagePathLabel;

/**
 * P3.3-1: reads give the model what is really there, so it has nothing to state as fact that a tool didn't give it.
 *
 * - get_page_content reads a page named by its path (P2.9's browser check 6: the model read the current page instead)
 * - the block lists never guess a page path (BF-12)
 * - content reads give translated links, absolute image URLs and image alt text (P1.10, BF-12, B-TRU-9)
 * - image and file page attributes are returned (BF-12)
 * - the topic tree shows categories and what is under them (BF-6)
 *
 * Throwaway pages, a throwaway topic tree and a removed PagePaths row are put back; the seed's photo
 * ("Neuron Test Person Photo") is used, not changed. No AI provider call.
 */
final class HonestReadsTest extends ConcreteTestCase
{
    private const PHOTO_TITLE = 'Neuron Test Person Photo';

    private ThrowawayPages $pages;

    private ?TopicTree $tree = null;

    /** @var list<array<string, mixed>> PagePaths rows this test removed */
    private array $removedPaths = [];

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        ThrowawayPages::actAs('superuser');
        Request::getInstance()->query->remove('pageID');
        foreach ($this->removedPaths as $row) {
            $this->db()->insert('PagePaths', $row);
        }
        $this->removedPaths = [];
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
        $this->pages->restore();
        $this->app()->make('cache/request')->flush();
    }

    // ---- get_page_content by path ---------------------------------------------------------------------------

    public function testGetPageContentReadsAPageByPath(): void
    {
        $page = $this->pages->create('Read by path', ['<p>Text only this page has.</p>']);
        $path = (string) $page->getCollectionPath();

        $read = $this->json($this->tool(new PagesToolkit(), 'get_page_content', ['pagePath' => $path]));
        $this->assertSame((int) $page->getCollectionID(), (int) $read['id']);
        $this->assertSame($path, $read['path']);
        $this->assertStringContainsString('Text only this page has.', $read['text']);

        $home = $this->json($this->tool(new PagesToolkit(), 'get_page_content', ['pagePath' => '/']));
        $homeID = (int) $this->app()->make('site')->getSite()->getSiteHomePageID();
        $this->assertSame($homeID, (int) $home['id']);

        $missing = $this->tool(new PagesToolkit(), 'get_page_content', ['pagePath' => '/neuron-test/no-such-page-p331']);
        $this->assertFalse(ToolKind::succeeded($missing), $missing);
        $this->assertStringContainsString('/neuron-test/no-such-page-p331', $missing);

        // A stale ID (the current page's, in P2.9) and the path the user named: refused, not silently the ID.
        $other = (int) Page::getByPath('/neuron-test')->getCollectionID();
        $both = $this->tool(new PagesToolkit(), 'get_page_content', ['pageID' => $other, 'pagePath' => $path]);
        $this->assertFalse(ToolKind::succeeded($both), $both);
        $this->assertStringContainsString($path, $both);

        $neither = $this->tool(new PagesToolkit(), 'get_page_content', []);
        $this->assertFalse(ToolKind::succeeded($neither), $neither);
    }

    // ---- no guessed paths ------------------------------------------------------------------------------------

    public function testBlockListsNeverGuessAPath(): void
    {
        [$page, $containerBlockID] = $this->newContainerPage();
        $cID = (int) $page->getCollectionID();
        $handle = (string) $page->getCollectionHandle();
        $name = (string) $page->getCollectionName();

        foreach ($this->db()->fetchAllAssociative('SELECT * FROM PagePaths WHERE cID = ?', [$cID]) as $row) {
            $this->removedPaths[] = $row;
        }
        $this->assertNotSame([], $this->removedPaths, 'The throwaway page has no PagePaths row to remove');
        $this->db()->executeStatement('DELETE FROM PagePaths WHERE cID = ?', [$cID]);
        CacheLocal::flush();

        $search = $this->tool(new BlocksToolkit(), 'search_blocks_by_type', ['blockTypeHandle' => 'core_container', 'limit' => 5]);
        $this->assertStringContainsString("page ID {$cID} (no path)", $search);
        $this->assertStringNotContainsString('/' . $handle, $search);

        $nested = $this->tool(new BlocksToolkit(), 'list_container_blocks', ['containerBlockID' => $containerBlockID]);
        $this->assertStringContainsString("page ID {$cID} (no path)", $nested);
        $this->assertStringNotContainsString('/' . $name, $nested);
    }

    public function testThePathLabelIsTheStoredPathTheHomePageOrNone(): void
    {
        $homeID = (int) $this->app()->make('site')->getSite()->getSiteHomePageID();

        $this->assertSame('/neuron-test', PagePathLabel::of(123, '/neuron-test'));
        $this->assertSame('/', PagePathLabel::of($homeID, null));
        $this->assertSame('/', PagePathLabel::of($homeID, ''));
        $this->assertNull(PagePathLabel::of($homeID + 999999, null));
        $this->assertSame('page ID 5 (no path)', PagePathLabel::describe(5, null));
    }

    // ---- links, images and alt text --------------------------------------------------------------------------

    public function testPageContentLinksAndImagesAreUsable(): void
    {
        $target = $this->pages->create('Link target', ['<p>Linked to.</p>']);
        $targetID = (int) $target->getCollectionID();
        $fID = $this->photoID();
        $page = $this->pages->create('Links and pictures', [
            '<p>See <a href="{CCM:CID_' . $targetID . '}">the target</a>.</p><p><concrete-picture fid="' . $fID . '" alt="Photo alt" /></p>',
        ]);

        $read = $this->json($this->tool(new PagesToolkit(), 'get_page_content', ['pageID' => (int) $page->getCollectionID()]));

        $this->assertStringNotContainsString('{CCM:', $read['html']);
        $this->assertStringNotContainsString('<concrete-picture', $read['html']);
        $this->assertStringContainsString('href="' . $target->getCollectionLink() . '"', $read['html']);
        $this->assertMatchesRegularExpression('~href="https?://[^"/]+/~', $read['html']);
        $this->assertMatchesRegularExpression('~<img[^>]+src="https?://[^"/]+/~', $read['html']);
        $this->assertDoesNotMatchRegularExpression('~\ssrc="/(?!/)~', $read['html']);
        $this->assertStringContainsString('alt="Photo alt"', $read['html']);
        // The stored form, as the write tools keep it.
        $this->assertStringContainsString('{CCM:CID_' . $targetID . '}', $read['html_raw']);
    }

    public function testImageBlockAltTextIsReturned(): void
    {
        $page = $this->pages->create('Image block', []);
        $fID = $this->photoID();
        $page->addBlock(BlockType::getByHandle('image'), 'Main', ['fID' => $fID, 'altText' => 'P3.3-1 alt', 'title' => '']);
        CacheLocal::flush();

        $read = $this->json($this->tool(new PagesToolkit(), 'get_page_content', ['pageID' => (int) $page->getCollectionID()]));

        $images = array_values(array_filter($read['blocks'], static fn (array $b): bool => $b['blockType'] === 'image'));
        $this->assertCount(1, $images);
        $this->assertFalse($images[0]['hasText']);
        $this->assertSame($fID, (int) $images[0]['image']['fileID']);
        $this->assertSame('P3.3-1 alt', $images[0]['image']['alt']);
        $this->assertMatchesRegularExpression('~^https?://[^/]+/~', (string) $images[0]['image']['url']);
        $this->assertStringContainsString('alt="P3.3-1 alt"', $read['html']);
    }

    public function testControllerDataImagesHaveAbsoluteSrc(): void
    {
        $fID = $this->photoID();
        $page = $this->pages->create('Controller picture', ['<p><concrete-picture fid="' . $fID . '" alt="Photo alt" /></p>']);
        $bID = $this->pages->blockIDs((int) $page->getCollectionID())[0];

        $output = $this->tool(new BlocksToolkit(), 'get_block_controller_data', ['blockID' => $bID, 'pageID' => (int) $page->getCollectionID()]);

        $this->assertMatchesRegularExpression('~<img[^>]+src="https?://[^"/]+/~', $output, $output);
        $this->assertDoesNotMatchRegularExpression('~\ssrc="/(?!/)~', $output, $output);
    }

    // ---- file attributes -------------------------------------------------------------------------------------

    public function testFileAttributesAreReturned(): void
    {
        $page = $this->pages->create('Thumbnail', ['<p>Has a thumbnail.</p>']);
        $cID = (int) $page->getCollectionID();
        $fID = $this->photoID();
        $page->setAttribute('thumbnail', File::getByID($fID));
        CacheLocal::flush();

        $read = $this->json($this->tool(new PagesToolkit(), 'get_page', ['pageID' => $cID]));
        $this->assertArrayHasKey('thumbnail', $read['attributes'], 'get_page left out the image attribute');
        $this->assertSame($fID, (int) $read['attributes']['thumbnail']['fileID']);
        $this->assertSame(self::PHOTO_TITLE, $read['attributes']['thumbnail']['title']);
        $this->assertMatchesRegularExpression('~^https?://[^/]+/~', (string) $read['attributes']['thumbnail']['url']);

        Request::getInstance()->query->set('pageID', $cID);
        $context = $this->json($this->tool(new PagesToolkit(), 'get_current_page_context', []));
        $this->assertSame($cID, (int) $context['page']['id']);
        $this->assertSame($fID, (int) $context['attributes']['thumbnail']['fileID']);
    }

    // ---- topic categories ------------------------------------------------------------------------------------

    public function testTopicCategoriesAreShown(): void
    {
        $this->tree = TopicTree::add('P3.3-1 test tree ' . bin2hex(random_bytes(3)));
        $root = $this->tree->getRootTreeNodeObject();
        $regions = CategoryNode::add('Regions', $root);
        TopicNode::add('North', $regions);
        TopicNode::add('Loose', $root);

        $result = $this->json($this->tool(new TopicsToolkit(), 'get_topic_tree_structure', ['tree_name' => $this->tree->getTreeName()]));

        $byName = array_column($result['topics'], null, 'name');
        $this->assertArrayHasKey('Regions', $byName, 'The category is missing from the tree');
        $this->assertSame('category', $byName['Regions']['type']);
        $this->assertSame(['North'], array_column($byName['Regions']['children'], 'name'));
        $this->assertSame('topic', $byName['Regions']['children'][0]['type']);
        $this->assertSame('topic', $byName['Loose']['type']);
        $this->assertSame(2, $result['totalTopics']);
        $this->assertSame(1, $result['totalCategories']);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function tool(\NeuronAI\Tools\Toolkits\ToolkitInterface $toolkit, string $name, array $inputs): string
    {
        ThrowawayPages::actAs('superuser');

        return ThrowawayPages::runTool($toolkit, $name, $inputs);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $output): array
    {
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, $output);

        return $decoded;
    }

    private function photoID(): int
    {
        $fID = (int) $this->db()->fetchOne('SELECT MIN(fID) FROM FileVersions WHERE fvTitle = ? AND fvIsApproved = 1', [self::PHOTO_TITLE]);
        $this->assertGreaterThan(0, $fID, 'The seed photo "' . self::PHOTO_TITLE . '" does not exist. Run the seed script first.');

        return $fID;
    }

    /**
     * @return array{0: Page, 1: int} the page and its container block's ID
     */
    private function newContainerPage(): array
    {
        $page = $this->pages->create('Container', []);
        $container = $this->app()->make(EntityManagerInterface::class)->getRepository(Container::class)->findOneBy(['containerHandle' => 'row_two_column']);
        $this->assertNotNull($container, 'The row_two_column container does not exist');
        $containerBlock = $page->addBlock(BlockType::getByHandle('core_container'), 'Main', ['containerID' => $container->getContainerID()]);
        $containerBlock = Block::getByID($containerBlock->getBlockID(), $page, 'Main');
        $instance = $containerBlock->getController()->getContainerInstanceObject();
        $blockInstance = $this->app()->make(ContainerBlockInstance::class, ['block' => $containerBlock, 'instance' => $instance]);
        $column1 = (new ContainerArea($blockInstance, 'Column 1'))->getSubAreaObject($page);
        $page->addBlock(BlockType::getByHandle('content'), $column1, ['content' => '<p>Nested text.</p>']);
        CacheLocal::flush();

        return [$page, (int) $containerBlock->getBlockID()];
    }
}
