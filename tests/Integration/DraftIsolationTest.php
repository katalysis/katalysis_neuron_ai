<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\Area;
use Concrete\Core\Area\ContainerArea;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\Page\Container;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Container\ContainerBlockInstance;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Permission\Access\Entity\UserEntity;
use Concrete\Core\Permission\Key\Key as PermissionKey;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use NeuronAI\Tools\ToolInterface;

/**
 * P1.8: content write tools change only the new draft version. The approved
 * (live) version keeps its blocks, text and version number (B-WRT-3, B-T1-1).
 *
 * Every test builds its own throwaway page under /neuron-test, as the superuser,
 * and deletes it again in tearDown(), so a failing test can't damage the seeded
 * pages. (Copying a seeded page doesn't work: Concrete's page copy shares the
 * block rows with the original, so an edit to the copy changes the seeded page.)
 * The tools run as the non-admin `neuron_editor`, as in the scenarios. Nothing
 * here calls the AI provider.
 *
 * Needs the seeded dev site (seed_test_content.php) for /neuron-test and the
 * editor user.
 */
final class DraftIsolationTest extends ConcreteTestCase
{
    /** @var int[] IDs of the throwaway pages, deleted in tearDown() */
    private array $copies = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Adding a page loads its theme's controller class, which the installed
        // packages' autoloaders provide. The test boot doesn't register them.
        ConcreteBoot::boot()->setupPackageAutoloaders();
    }

    protected function tearDown(): void
    {
        $this->actAs('superuser');
        foreach ($this->copies as $cID) {
            $page = Page::getByID($cID);
            if ($page && !$page->isError()) {
                $page->delete();
            }
        }
        $this->copies = [];
        Request::getInstance()->setCustomRequestUser(-1);
    }

    // ---- Helpers -----------------------------------------------------------

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
    }

    /**
     * A page under /neuron-test with three Content blocks in Main, with the same
     * text as the seeded Headings page. Leaves the tests acting as neuron_editor.
     */
    private function newHeadingsPage(): int
    {
        $page = $this->newPage();
        $content = BlockType::getByHandle('content');
        $page->addBlock($content, 'Main', ['content' =>
            '<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>'
            . '<h3>Seed Heading Three A</h3><p>Text under the first h3 heading.</p>'
            . '<h3>Seed Heading Three B</h3><p>Text under the second h3 heading.</p>']);
        $page->addBlock($content, 'Main', ['content' =>
            '<p><strong>Seed Bold Heading One</strong></p><p>Body text under the first bold-paragraph heading.</p>']);
        $page->addBlock($content, 'Main', ['content' => '<h2>Seed Closing Heading</h2><p>A short closing paragraph.</p>']);

        return $this->startFromOneApprovedVersion($page);
    }

    /**
     * A page under /neuron-test with a two-column container: a Content block
     * with a bold-paragraph heading in column 1, one with h2/h3 in column 2.
     */
    private function newColumnsPage(): int
    {
        $app = $this->app();
        $page = $this->newPage();
        $container = $app->make(EntityManagerInterface::class)->getRepository(Container::class)->findOneBy(['containerHandle' => 'row_two_column']);
        $this->assertNotNull($container, 'The row_two_column container does not exist');
        $containerBlock = $page->addBlock(BlockType::getByHandle('core_container'), 'Main', ['containerID' => $container->getContainerID()]);
        $containerBlock = Block::getByID($containerBlock->getBlockID(), $page, 'Main');
        $instance = $containerBlock->getController()->getContainerInstanceObject();
        $blockInstance = $app->make(ContainerBlockInstance::class, ['block' => $containerBlock, 'instance' => $instance]);
        $column1 = (new ContainerArea($blockInstance, 'Column 1'))->getSubAreaObject($page);
        $column2 = (new ContainerArea($blockInstance, 'Column 2'))->getSubAreaObject($page);
        $content = BlockType::getByHandle('content');
        $page->addBlock($content, $column1, ['content' =>
            '<p><strong>Column One Bold Heading A</strong></p><p>Body text in column one, under bold heading A.</p>']);
        $page->addBlock($content, $column2, ['content' =>
            '<h2>Column Two Heading</h2><p>Text in column two.</p><h3>Column Two Sub Heading</h3><p>More text in column two.</p>']);

        return $this->startFromOneApprovedVersion($page);
    }

    private function newPage(): Page
    {
        $this->actAs('superuser');
        $parent = Page::getByPath('/neuron-test');
        $this->assertFalse($parent->isError(), '/neuron-test does not exist. Run the seed script first.');
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => 'Draft isolation ' . $unique,
            'cHandle' => 'draft-isolation-' . $unique,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $this->assertFalse($page->isError());
        $this->copies[] = (int) $page->getCollectionID();
        $this->assertStringStartsWith('/neuron-test/', (string) Page::getByID($page->getCollectionID())->getCollectionPath());

        return $page;
    }

    private function startFromOneApprovedVersion(Page $page): int
    {
        $cID = (int) $page->getCollectionID();
        $versions = $this->db()->fetchAllAssociative('SELECT cvID, cvIsApproved FROM CollectionVersions WHERE cID = ?', [$cID]);
        $this->assertCount(1, $versions, 'The page should start with a single version');
        $this->assertSame(1, (int) $versions[0]['cvIsApproved'], 'The first version should be approved');
        $this->actAs('neuron_editor');

        return $cID;
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * Runs a tool of the Pages toolkit with the given inputs, as the current user.
     *
     * @return string the tool's result
     */
    private function callPagesTool(string $name, array $inputs): string
    {
        return $this->runTool((new PagesToolkit())->tools(), $name, $inputs);
    }

    private function callVersionsTool(string $name, array $inputs): string
    {
        return $this->runTool((new VersionsToolkit())->tools(), $name, $inputs);
    }

    /**
     * @param ToolInterface[] $tools
     */
    private function runTool(array $tools, string $name, array $inputs): string
    {
        foreach ($tools as $tool) {
            if ($tool->getName() === $name) {
                $tool = clone $tool;
                $tool->setInputs($inputs)->execute();

                return (string) $tool->getResult();
            }
        }
        $this->fail("No tool named {$name}");
    }

    /**
     * @return array{cvID: int, isApproved: bool, blocks: list<array<string, mixed>>}[] one entry per version, by version number
     */
    private function versions(int $cID): array
    {
        $db = $this->db();
        $result = [];
        foreach ($db->fetchAllAssociative('SELECT cvID, cvIsApproved FROM CollectionVersions WHERE cID = ? ORDER BY cvID', [$cID]) as $v) {
            $blocks = [];
            foreach ($db->fetchAllAssociative(
                'SELECT cvb.bID, cvb.arHandle, cvb.cbDisplayOrder, bt.btHandle
                 FROM CollectionVersionBlocks cvb
                 JOIN Blocks b ON b.bID = cvb.bID
                 JOIN BlockTypes bt ON bt.btID = b.btID
                 WHERE cvb.cID = ? AND cvb.cvID = ?
                 ORDER BY cvb.arHandle, cvb.cbDisplayOrder, cvb.bID',
                [$cID, $v['cvID']]
            ) as $row) {
                $blocks[] = [
                    'bID' => (int) $row['bID'],
                    'area' => (string) $row['arHandle'],
                    'order' => (int) $row['cbDisplayOrder'],
                    'type' => (string) $row['btHandle'],
                    'content' => $row['btHandle'] === 'content'
                        ? (string) $db->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$row['bID']])
                        : null,
                ];
            }
            $result[(int) $v['cvID']] = ['cvID' => (int) $v['cvID'], 'isApproved' => (bool) $v['cvIsApproved'], 'blocks' => $blocks];
        }

        return $result;
    }

    /**
     * @return array{cvID: int, isApproved: bool, blocks: list<array<string, mixed>>}
     */
    private function approved(int $cID): array
    {
        foreach ($this->versions($cID) as $version) {
            if ($version['isApproved']) {
                return $version;
            }
        }
        $this->fail('The page has no approved version');
    }

    /**
     * The newest version, which must be a draft (not approved).
     *
     * @return array{cvID: int, isApproved: bool, blocks: list<array<string, mixed>>}
     */
    private function draft(int $cID): array
    {
        $versions = $this->versions($cID);
        $latest = end($versions);
        $this->assertFalse($latest['isApproved'], 'The newest version should be a draft');

        return $latest;
    }

    /**
     * Counts of block rows that nothing refers to any more, and of Content block
     * data rows. An undo that leaves stray blocks behind changes one of them.
     *
     * @return array{orphanBlocks: int, contentRows: int}
     */
    private function blockCounts(): array
    {
        $db = $this->db();

        return [
            'orphanBlocks' => (int) $db->fetchOne('SELECT COUNT(*) FROM Blocks b LEFT JOIN CollectionVersionBlocks cvb ON cvb.bID = b.bID WHERE cvb.bID IS NULL'),
            'contentRows' => (int) $db->fetchOne('SELECT COUNT(*) FROM btContentLocal'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function contentOf(array $blocks, ?string $area = null): string
    {
        $text = '';
        foreach ($blocks as $block) {
            if ($area === null || $block['area'] === $area) {
                $text .= (string) $block['content'];
            }
        }

        return $text;
    }

    // ---- batch_update_page_content -----------------------------------------

    public function testBatchUpdatePageContentLeavesTheApprovedVersionUntouched(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->approved($cID);
        $first = $before['blocks'][0];

        $this->callPagesTool('batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [['areaHandle' => 'Main', 'blockID' => $first['bID'], 'value' => ['content' => $first['content'] . '<p>Draft edit</p>']]],
        ]);

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'One draft, no more');
        $draft = $this->draft($cID);
        $this->assertCount(count($before['blocks']), $draft['blocks']);
        $this->assertStringContainsString('Draft edit', $this->contentOf($draft['blocks']));
        $this->assertStringNotContainsString('Draft edit', $this->contentOf($this->approved($cID)['blocks']));
    }

    // ---- batch_update_content_headings -------------------------------------

    public function testBatchUpdateContentHeadingsLeavesTheApprovedVersionUntouched(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->approved($cID);

        $result = $this->callPagesTool('batch_update_content_headings', [
            'pageID' => $cID,
            'headingTexts' => ['Seed Heading Three A'],
            'targetTag' => 'h2',
        ]);

        $this->assertStringContainsString('"updatedCount":1', $result);
        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'One draft, no more');
        $this->assertMatchesRegularExpression('#<h2[^>]*>\s*Seed Heading Three A#', $this->contentOf($this->draft($cID)['blocks']));
        $this->assertMatchesRegularExpression('#<h3[^>]*>\s*Seed Heading Three A#', $this->contentOf($before['blocks']));
    }

    // ---- One draft per page ------------------------------------------------

    public function testRepeatedHeadingCallsBuildOneDraftAndKeepEveryEdit(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->approved($cID);

        foreach (['Seed Heading Three A', 'Seed Heading Three B'] as $heading) {
            $result = $this->callPagesTool('batch_update_content_headings', [
                'pageID' => $cID,
                'headingTexts' => [$heading],
                'targetTag' => 'h2',
            ]);
            $this->assertStringContainsString('"updatedCount":1', $result, "{$heading} should be found, on the second call too");
        }

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'Both calls edit the same draft');
        $draftText = $this->contentOf($this->draft($cID)['blocks']);
        $this->assertMatchesRegularExpression('#<h2[^>]*>\s*Seed Heading Three A#', $draftText);
        $this->assertMatchesRegularExpression('#<h2[^>]*>\s*Seed Heading Three B#', $draftText);
    }

    public function testUpdateBlockInPageAreaMakesOneDraftAndLeavesTheApprovedVersionUntouched(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->approved($cID);
        $first = $before['blocks'][0];

        $this->callPagesTool('update_block_in_page_area', [
            'pageID' => $cID,
            'areaHandle' => 'Main',
            'blockID' => $first['bID'],
            'value' => ['content' => $first['content'] . '<p>Draft edit</p>'],
        ]);

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'One draft, not two');
        $draft = $this->draft($cID);
        $this->assertCount(count($before['blocks']), $draft['blocks']);
        $this->assertStringContainsString('Draft edit', $this->contentOf($draft['blocks']));
    }

    public function testDifferentToolsInOneRequestShareOneDraft(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->approved($cID);
        $second = $before['blocks'][1];

        // The first tool makes the draft and copies a block, so block IDs in the draft change.
        $this->callPagesTool('update_block_in_page_area', [
            'pageID' => $cID,
            'areaHandle' => 'Main',
            'blockID' => $before['blocks'][2]['bID'],
            'value' => ['content' => $before['blocks'][2]['content'] . '<p>First edit</p>'],
        ]);
        $this->callPagesTool('batch_update_content_headings', [
            'pageID' => $cID,
            'headingTexts' => ['Seed Heading Three A'],
            'targetTag' => 'h2',
        ]);
        // A second block edit, by the block ID that is current in the draft.
        $draftBlock = $this->draft($cID)['blocks'][1];
        $this->assertSame($second['content'], $draftBlock['content']);
        $this->callPagesTool('batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [['areaHandle' => 'Main', 'blockID' => $draftBlock['bID'], 'value' => ['content' => $draftBlock['content'] . '<p>Second edit</p>']]],
        ]);

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'Three tool calls, one draft');
        $draftText = $this->contentOf($this->draft($cID)['blocks']);
        $this->assertStringContainsString('First edit', $draftText);
        $this->assertStringContainsString('Second edit', $draftText);
        $this->assertMatchesRegularExpression('#<h2[^>]*>\s*Seed Heading Three A#', $draftText);
    }

    public function testMoveBlockBetweenAreasMakesOneDraftAndLeavesTheApprovedVersionUntouched(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->approved($cID);
        $moved = $before['blocks'][0];

        // As the superuser: the editor has no right to add blocks to a new area (see the next test).
        $this->actAs('superuser');
        $this->callPagesTool('move_block_between_areas', [
            'pageID' => $cID,
            'fromAreaHandle' => 'Main',
            'toAreaHandle' => 'Sidebar',
            'blockID' => $moved['bID'],
        ]);

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'One draft, not two');
        $draft = $this->draft($cID);
        $areas = array_column($draft['blocks'], 'area');
        sort($areas);
        $this->assertSame(['Main', 'Main', 'Sidebar'], $areas);
        $this->assertSame('Sidebar', $this->areaOf($draft, $moved['content']));
    }

    /**
     * @param array{blocks: list<array<string, mixed>>} $version
     */
    private function areaOf(array $version, string $content): string
    {
        foreach ($version['blocks'] as $block) {
            if ($block['content'] === $content) {
                return $block['area'];
            }
        }
        $this->fail('No block with that content');
    }

    public function testARefusedMoveLeavesNoDraftBehind(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->versions($cID);

        try {
            $this->callPagesTool('move_block_between_areas', [
                'pageID' => $cID,
                'fromAreaHandle' => 'Main',
                'toAreaHandle' => 'Sidebar',
                'blockID' => $before[array_key_first($before)]['blocks'][0]['bID'],
            ]);
            $this->fail('The editor should not be allowed to add blocks to the Sidebar area');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Permission denied', $e->getMessage());
        }

        $this->assertSame($before, $this->versions($cID), 'A refused move must not make a draft');
    }

    public function testARefusedBlockUpdateLeavesNoDraftBehind(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->versions($cID);

        try {
            // No such block: refused before anything is written.
            $this->callPagesTool('update_block_in_page_area', [
                'pageID' => $cID,
                'areaHandle' => 'Main',
                'blockID' => 999999999,
                'value' => ['content' => '<p>x</p>'],
            ]);
            $this->fail('Updating a block that does not exist should fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        $this->assertSame($before, $this->versions($cID), 'A refused update must not make a draft');
    }

    /**
     * Lets neuron_editor add blocks to Main on one throwaway page, as the seed does for
     * /neuron-test/headings (adding a block is governed by the area's add-block key, which the
     * page grant doesn't cover). Runs as the superuser and leaves the tests acting as neuron_editor.
     */
    private function allowEditorToAddBlocksToMain(int $cID): void
    {
        $this->actAs('superuser');
        $editor = $this->app()->make(UserInfoRepository::class)->getByUserName('neuron_editor');
        $main = Area::getOrCreate(Page::getByID($cID), 'Main');
        $main->overridePagePermissions();
        $key = PermissionKey::getByHandle('add_block_to_area');
        $key->setPermissionObject($main);
        $access = $key->getPermissionAccessObject()->duplicate();
        $access->addListItem(UserEntity::getOrCreate($editor));
        $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
        $this->actAs('neuron_editor');
    }

    public function testAddingTwoBlocksInOneRequestMakesOneDraftAndKeepsBoth(): void
    {
        $cID = $this->newHeadingsPage();
        $this->allowEditorToAddBlocksToMain($cID);
        $before = $this->approved($cID);

        foreach (['First added block', 'Second added block'] as $text) {
            $this->callPagesTool('add_block_to_page_area', [
                'pageID' => $cID,
                'areaHandle' => 'Main',
                'blockType' => 'content',
                'value' => ['content' => '<p>' . $text . '</p>'],
            ]);
        }

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'Both adds go to the same draft');
        $draft = $this->draft($cID);
        $this->assertCount(count($before['blocks']) + 2, $draft['blocks']);
        $draftText = $this->contentOf($draft['blocks']);
        $this->assertStringContainsString('First added block', $draftText);
        $this->assertStringContainsString('Second added block', $draftText);
    }

    // ---- Delete ------------------------------------------------------------

    public function testDeletingABlockRemovesItFromTheDraftOnly(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->approved($cID);
        $deleted = $before['blocks'][1];

        $result = $this->callPagesTool('delete_block_from_page_area', [
            'pageID' => $cID,
            'areaHandle' => 'Main',
            'blockID' => $deleted['bID'],
        ]);

        $this->assertStringContainsString('"deleted":true', $result);
        $this->assertSame($before, $this->approved($cID), 'The block is still in the approved version, with its text');
        $this->assertCount(2, $this->versions($cID), 'The delete is in one draft');
        $draft = $this->draft($cID);
        $this->assertNotContains($deleted['bID'], array_column($draft['blocks'], 'bID'), 'The block is gone from the draft');
        $this->assertCount(count($before['blocks']) - 1, $draft['blocks']);
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM btContentLocal WHERE bID = ?', [$deleted['bID']]), 'The block data is kept for the live version');
    }

    public function testDiscardingTheDraftAfterADeleteBringsEverythingBack(): void
    {
        $cID = $this->newHeadingsPage();
        $beforeVersions = $this->versions($cID);
        $beforeCounts = $this->blockCounts();

        $this->callPagesTool('delete_block_from_page_area', [
            'pageID' => $cID,
            'areaHandle' => 'Main',
            'blockID' => $beforeVersions[array_key_first($beforeVersions)]['blocks'][0]['bID'],
        ]);
        $this->callVersionsTool('discard_draft_version', ['pageID' => $cID, 'versionID' => $this->draft($cID)['cvID']]);

        $this->assertSame($beforeVersions, $this->versions($cID), 'Versions, blocks and text are as they were');
        $this->assertSame($beforeCounts, $this->blockCounts(), 'No stray blocks or block data are left behind');
    }

    // ---- update_page -------------------------------------------------------

    public function testUpdatePageRefusesAPageTypeChangeAndLeavesTheLivePageAlone(): void
    {
        $cID = $this->newHeadingsPage();
        // Any other page type that exists will do: the tool must refuse before it looks at the page.
        $otherType = PageType::getByHandle('core_desktop') ? 'core_desktop' : null;
        if ($otherType === null) {
            $this->markTestSkipped('The site has no second page type to try');
        }
        $typeBefore = $this->db()->fetchOne('SELECT ptID FROM Pages WHERE cID = ?', [$cID]);
        $versionsBefore = $this->versions($cID);

        try {
            $this->callPagesTool('update_page', ['pageID' => $cID, 'pageType' => $otherType]);
            $this->fail('A page type change should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('page type', $e->getMessage());
            $this->assertStringContainsString('person', $e->getMessage());
        }

        $this->assertEquals($typeBefore, $this->db()->fetchOne('SELECT ptID FROM Pages WHERE cID = ?', [$cID]), 'The live page type is unchanged');
        $this->assertSame($versionsBefore, $this->versions($cID), 'A refused change makes no draft');
    }

    public function testUpdatePageRenamesInADraftAndAcceptsTheCurrentPageType(): void
    {
        $cID = $this->newHeadingsPage();
        $nameBefore = (string) $this->db()->fetchOne('SELECT cvName FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 1', [$cID]);

        $this->callPagesTool('update_page', ['pageID' => $cID, 'name' => 'Renamed in a draft', 'pageType' => 'page']);

        $this->assertSame($nameBefore, (string) $this->db()->fetchOne('SELECT cvName FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 1', [$cID]), 'The live name is unchanged');
        $this->assertCount(2, $this->versions($cID), 'The rename is in one draft');
        $this->assertSame('Renamed in a draft', (string) $this->db()->fetchOne('SELECT cvName FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 0', [$cID]));
    }

    public function testNestedColumnBlocksAreCopiedIntoTheDraftToo(): void
    {
        $cID = $this->newColumnsPage();
        $before = $this->approved($cID);

        $result = $this->callPagesTool('batch_update_content_headings', [
            'pageID' => $cID,
            'matchMode' => 'bold_paragraph',
            'areaHandleContains' => 'Column 1',
            'targetTag' => 'h3',
        ]);

        $this->assertStringContainsString('"updatedCount":1', $result);
        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $draft = $this->draft($cID);
        $this->assertSame(
            array_column($before['blocks'], 'area'),
            array_column($draft['blocks'], 'area'),
            'The draft keeps every block in the same (sub-)area, in the same order'
        );
        $this->assertSame(array_column($before['blocks'], 'type'), array_column($draft['blocks'], 'type'));
        $this->assertStringContainsString('<h3', $this->contentOf($draft['blocks'], $this->columnArea($before, 'Column 1')));
        $this->assertStringNotContainsString('<h3', $this->contentOf($before['blocks'], $this->columnArea($before, 'Column 1')));
    }

    /**
     * @param array{blocks: list<array<string, mixed>>} $version
     */
    private function columnArea(array $version, string $column): string
    {
        foreach ($version['blocks'] as $block) {
            if (str_ends_with($block['area'], $column)) {
                return $block['area'];
            }
        }
        $this->fail("No block in {$column}");
    }

    // ---- Undo --------------------------------------------------------------

    public function testDiscardingTheDraftLeavesThePageExactlyAsItWas(): void
    {
        $cID = $this->newHeadingsPage();
        $beforeVersions = $this->versions($cID);
        $beforeCounts = $this->blockCounts();

        $this->callPagesTool('batch_update_content_headings', [
            'pageID' => $cID,
            'headingTexts' => ['Seed Heading Three A', 'Seed Heading Three B'],
            'targetTag' => 'h2',
        ]);
        $draft = $this->draft($cID);

        $this->callVersionsTool('discard_draft_version', ['pageID' => $cID, 'versionID' => $draft['cvID']]);

        $this->assertSame($beforeVersions, $this->versions($cID), 'Versions, blocks and text are as they were');
        $this->assertSame($beforeCounts, $this->blockCounts(), 'No stray blocks or block data are left behind');
    }
}
