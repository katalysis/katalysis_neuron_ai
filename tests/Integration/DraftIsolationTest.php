<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\Area;
use Concrete\Core\Area\ContainerArea;
use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\Page\Container;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Container\ContainerBlockInstance;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Tests\Support\AreaGrantFixture;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
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

    /** Area grants made by allowEditorToAddBlocksToMain(), removed in tearDown() (P1.12) */
    private ?AreaGrantFixture $grants = null;

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
        // Page::delete() leaves the pages' area permission rows: the fixture removes them, and the access it made.
        $this->grants?->restore();
        $this->grants = null;
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
     * Runs a tool of the Pages toolkit with the given inputs, as the current user. Since P3.3-4 that is any of the three
     * toolkits PagesToolkit was split into.
     *
     * @return string the tool's result
     */
    private function callPagesTool(string $name, array $inputs): string
    {
        $tools = array_merge((new PagesToolkit())->tools(), (new ContentToolkit())->tools(), (new BatchEditToolkit())->tools());

        return $this->runTool($tools, $name, $inputs);
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
        $this->grants ??= new AreaGrantFixture($this->app());
        $this->grants->allowUserToAddBlocks($cID, 'neuron_editor');
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

    // ---- Page attributes and versions toolkits -----------------------------

    private function callAttributesTool(object $toolkit, string $name, array $inputs): string
    {
        return $this->runTool($toolkit->tools(), $name, $inputs);
    }

    /**
     * A value of a text or textarea page attribute in one version, read from the database.
     */
    private function attributeValue(int $cID, int $cvID, string $handle): ?string
    {
        $value = $this->db()->fetchOne(
            'SELECT ad.value FROM CollectionAttributeValues cav
             JOIN AttributeKeys ak ON ak.akID = cav.akID
             JOIN atDefault ad ON ad.avID = cav.avID
             WHERE cav.cID = ? AND cav.cvID = ? AND ak.akHandle = ?',
            [$cID, $cvID, $handle]
        );

        return $value === false ? null : (string) $value;
    }

    /**
     * Gives the approved version of the page meta_title and meta_description values, so the
     * draft starts with attribute values that the live version also holds.
     */
    private function giveLiveVersionAttributes(int $cID): void
    {
        $this->actAs('superuser');
        $page = Page::getByID($cID, 'ACTIVE');
        $page->setAttribute('meta_title', 'Live title');
        $page->setAttribute('meta_description', 'Live description');
        $this->actAs('neuron_editor');
    }

    private function assertLiveAttributesUntouched(int $cID): void
    {
        $liveVersion = $this->approved($cID)['cvID'];
        $this->assertSame('Live title', $this->attributeValue($cID, $liveVersion, 'meta_title'), 'The live meta_title must be unchanged');
        $this->assertSame('Live description', $this->attributeValue($cID, $liveVersion, 'meta_description'), 'The live meta_description must be unchanged');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('attributeToolkits')]
    public function testTwoAttributeWritesInOneRequestShareOneDraft(string $toolkitClass): void
    {
        $cID = $this->newHeadingsPage();
        $this->giveLiveVersionAttributes($cID);
        $before = $this->approved($cID);
        $toolkit = new $toolkitClass();

        $this->callAttributesTool($toolkit, 'set_page_attribute_value', ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Draft title']);
        $this->callAttributesTool($toolkit, 'set_page_attribute_value', ['pageID' => $cID, 'handle' => 'meta_description', 'value' => 'Draft description']);

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'Both writes go to the same draft');
        $this->assertLiveAttributesUntouched($cID);
        $draftVersion = $this->draft($cID)['cvID'];
        $this->assertSame('Draft title', $this->attributeValue($cID, $draftVersion, 'meta_title'));
        $this->assertSame('Draft description', $this->attributeValue($cID, $draftVersion, 'meta_description'));
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function attributeToolkits(): array
    {
        return [
            'PageAttributesToolkit' => [PageAttributesToolkit::class],
        ];
    }

    public function testAttributeWriteAfterAnotherToolsDraftUsesThatDraftWhenThePageIsFoundByName(): void
    {
        $cID = $this->newHeadingsPage();
        $this->giveLiveVersionAttributes($cID);
        $before = $this->approved($cID);
        $name = (string) $this->db()->fetchOne('SELECT cvName FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 1', [$cID]);

        // Another tool makes the draft first. A page found by name comes from a page list, as its approved version.
        $this->callPagesTool('update_page', ['pageID' => $cID, 'description' => 'A draft description']);
        $this->callAttributesTool(new PageAttributesToolkit(), 'set_page_attribute_value', ['pageName' => $name, 'handle' => 'meta_title', 'value' => 'Draft title']);

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'The attribute goes to the draft that already exists');
        $this->assertLiveAttributesUntouched($cID);
        $this->assertSame('Draft title', $this->attributeValue($cID, $this->draft($cID)['cvID'], 'meta_title'));
        $this->assertSame('A draft description', (string) $this->db()->fetchOne('SELECT cvDescription FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 0', [$cID]), 'The other tool\'s edit is still there');
    }

    public function testARefusedAttributeWriteLeavesNoDraftBehind(): void
    {
        $cID = $this->newHeadingsPage();
        $before = $this->versions($cID);

        try {
            $this->callAttributesTool(new PageAttributesToolkit(), 'set_page_attribute_value', ['pageID' => $cID, 'handle' => 'no_such_attribute_handle', 'value' => 'x']);
            $this->fail('An unknown attribute should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        $this->assertSame($before, $this->versions($cID), 'A refused write must not make a draft');
    }

    public function testCreatePageDraftReusesAnExistingDraftAndSaysSo(): void
    {
        $cID = $this->newHeadingsPage();
        $versions = new VersionsToolkit();

        $first = $this->callAttributesTool($versions, 'create_page_draft', ['pageID' => $cID]);
        $this->assertStringContainsString('created draft v2', $first);
        $second = $this->callAttributesTool($versions, 'create_page_draft', ['pageID' => $cID]);
        $this->assertStringContainsString('reused existing draft v2', $second);

        $this->assertCount(2, $this->versions($cID), 'Calling it twice makes one draft');
    }

    public function testEditsAfterCreatePageDraftAllLandInThatDraft(): void
    {
        $cID = $this->newHeadingsPage();
        $this->giveLiveVersionAttributes($cID);
        $before = $this->approved($cID);

        $this->callAttributesTool(new VersionsToolkit(), 'create_page_draft', ['pageID' => $cID]);
        $this->callAttributesTool(new PageAttributesToolkit(), 'set_page_attribute_value', ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Draft title']);
        $this->callPagesTool('update_page', ['pageID' => $cID, 'description' => 'A draft description']);

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'Three tools, one draft');
        $this->assertLiveAttributesUntouched($cID);
        $draftVersion = $this->draft($cID)['cvID'];
        $this->assertSame('Draft title', $this->attributeValue($cID, $draftVersion, 'meta_title'));
        $this->assertSame('A draft description', (string) $this->db()->fetchOne('SELECT cvDescription FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 0', [$cID]));
    }

    public function testCreatePageDraftBasedOnAnOlderVersionMakesANewDraftThatLaterWritesUse(): void
    {
        $cID = $this->newHeadingsPage();
        $this->callAttributesTool(new VersionsToolkit(), 'create_page_draft', ['pageID' => $cID]);
        $this->callAttributesTool(new PageAttributesToolkit(), 'set_page_attribute_value', ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'In the first draft']);

        $result = $this->callAttributesTool(new VersionsToolkit(), 'create_page_draft', ['pageID' => $cID, 'basedOnVersionID' => $this->approved($cID)['cvID']]);
        $this->assertStringContainsString('created draft v3', $result);
        $this->callAttributesTool(new PageAttributesToolkit(), 'set_page_attribute_value', ['pageID' => $cID, 'handle' => 'meta_description', 'value' => 'In the new draft']);

        $this->assertCount(3, $this->versions($cID), 'The approved version and two drafts');
        $this->assertSame('In the new draft', $this->attributeValue($cID, 3, 'meta_description'));
        $this->assertNull($this->attributeValue($cID, 3, 'meta_title'), 'The new draft was cloned from the approved version, not the first draft');
        $this->assertNull($this->attributeValue($cID, 2, 'meta_description'), 'The first draft is untouched by the later write');
        $this->assertSame('In the first draft', $this->attributeValue($cID, 2, 'meta_title'));
    }

    // ---- batch_update_page_content refuses blocks in containers and layouts ------

    /**
     * The seeded /neuron-test/columns page: its newest version's blocks, and its ID. The refusal
     * tests only read it (a refused call must not write anything). The newest version is used
     * because that is the version the tools edit, and a scenario run may have left a draft.
     *
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    private function seededColumnsPage(): array
    {
        $this->actAs('superuser');
        $page = Page::getByPath('/neuron-test/columns');
        $this->assertFalse($page->isError(), '/neuron-test/columns does not exist. Run the seed script first.');
        $cID = (int) $page->getCollectionID();
        $this->actAs('neuron_editor');
        $versions = $this->versions($cID);

        return [$cID, end($versions)['blocks']];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function blockInASubArea(array $blocks): array
    {
        foreach ($blocks as $block) {
            if (str_contains($block['area'], ' : ') && $block['type'] === 'content') {
                return $block;
            }
        }
        $this->fail('The page has no Content block in a sub-area');
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function blockInATopLevelArea(array $blocks): array
    {
        foreach ($blocks as $block) {
            if (!str_contains($block['area'], ' : ')) {
                return $block;
            }
        }
        $this->fail('The page has no block in a top-level area');
    }

    private function assertRefusedAsNested(int $cID, array $inputs): void
    {
        $versionsBefore = $this->versions($cID);
        $countsBefore = $this->blockCounts();

        try {
            $this->callPagesTool('batch_update_page_content', ['pageID' => $cID] + $inputs);
            $this->fail('A block inside a container or area layout should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('inside a container or area layout', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $this->assertSame($versionsBefore, $this->versions($cID), 'A refused call must not leave a draft, or change any version');
        $this->assertSame($countsBefore, $this->blockCounts(), 'A refused call must not leave stray blocks behind');
    }

    public function testBatchUpdateRefusesABlockInAContainerColumnAndLeavesNoDraft(): void
    {
        [$cID, $blocks] = $this->seededColumnsPage();
        $nested = $this->blockInASubArea($blocks);

        $this->assertRefusedAsNested($cID, ['blocks' => [['areaHandle' => $nested['area'], 'blockID' => $nested['bID'], 'value' => ['content' => '<p>x</p>']]]]);
    }

    public function testBatchUpdateRefusesANestedBlockEvenWhenTheAreaGivenIsTheTopLevelOne(): void
    {
        [$cID, $blocks] = $this->seededColumnsPage();
        $nested = $this->blockInASubArea($blocks);

        $this->assertRefusedAsNested($cID, ['blocks' => [['areaHandle' => 'Main', 'blockID' => $nested['bID'], 'value' => ['content' => '<p>x</p>']]]]);
    }

    public function testBatchUpdateRefusesTheWholeCallWhenOnlyOneBlockIsNested(): void
    {
        [$cID, $blocks] = $this->seededColumnsPage();
        $nested = $this->blockInASubArea($blocks);
        $topLevel = $this->blockInATopLevelArea($blocks);

        $this->assertRefusedAsNested($cID, [
            'continueOnError' => true,
            'blocks' => [
                ['areaHandle' => $topLevel['area'], 'blockID' => $topLevel['bID'], 'value' => ['content' => '<p>x</p>']],
                ['areaHandle' => $nested['area'], 'blockID' => $nested['bID'], 'value' => ['content' => '<p>y</p>']],
            ],
        ]);
    }

    public function testBatchUpdateRefusesABlockInAnAreaLayoutColumnAndLeavesNoDraft(): void
    {
        $cID = $this->newLayoutPage();
        $versions = $this->versions($cID);
        $nested = $this->blockInASubArea(end($versions)['blocks']);

        $this->assertRefusedAsNested($cID, ['blocks' => [['areaHandle' => $nested['area'], 'blockID' => $nested['bID'], 'value' => ['content' => '<p>x</p>']]]]);
    }

    /**
     * A page with a core area layout in Main, with a Content block in each of its two columns
     * (their areas are sub-areas, like the columns of a container). Leaves the tests acting as neuron_editor.
     */
    private function newLayoutPage(): int
    {
        $this->actAs('superuser');
        $page = $this->newPage();
        $layoutBlock = $page->addBlock(BlockType::getByHandle('core_area_layout'), 'Main', [
            // P3.9-4: a width per column, as core reads $post['width'][$i] (an empty list gave four warnings a run; 0 is core's default).
            'gridType' => 'FF', 'isautomated' => 1, 'spacing' => 0, 'columns' => 2, 'width' => [0, 0],
        ]);
        $layoutBlock = Block::getByID($layoutBlock->getBlockID(), $page, 'Main');
        $layout = $layoutBlock->getController()->getAreaLayoutObject();
        $layout->setAreaObject(Area::getOrCreate($page, 'Main'));
        foreach ($layout->getAreaLayoutColumns() as $index => $column) {
            $subArea = $column->getSubAreaObject();
            $subArea->create($page, $subArea->getAreaHandle());
            $page->addBlock(BlockType::getByHandle('content'), $subArea, ['content' => '<p>In layout column ' . ($index + 1) . '</p>']);
        }

        // Concrete caches areas and their permission parents for the rest of the request. Building the
        // layout leaves a stale entry that makes a permission check on a column block find no parent
        // area, so the editor is wrongly denied. A real request starts with an empty cache.
        $this->app()->make('cache/request')->flush();

        return $this->startFromOneApprovedVersion($page);
    }

    /**
     * The refusal message points at update_block_in_page_area, so it has to work in a column.
     */
    public function testUpdateBlockInPageAreaEditsABlockInAContainerColumnInTheDraft(): void
    {
        $cID = $this->newColumnsPage();
        $before = $this->approved($cID);
        $nested = $this->blockInASubArea($before['blocks']);

        $this->callPagesTool('update_block_in_page_area', [
            'pageID' => $cID,
            'areaHandle' => $nested['area'],
            'blockID' => $nested['bID'],
            'value' => ['content' => $nested['content'] . '<p>Column edit</p>'],
        ]);

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'One draft');
        $draft = $this->draft($cID);
        $this->assertStringContainsString('Column edit', $this->contentOf($draft['blocks'], $nested['area']));
        $this->assertSame(array_column($before['blocks'], 'area'), array_column($draft['blocks'], 'area'), 'Every block stays in its column');
    }

    public function testUpdateBlockInPageAreaEditsABlockInAnAreaLayoutColumnInTheDraft(): void
    {
        $cID = $this->newLayoutPage();
        $before = $this->approved($cID);
        $nested = $this->blockInASubArea($before['blocks']);

        $this->callPagesTool('update_block_in_page_area', [
            'pageID' => $cID,
            'areaHandle' => $nested['area'],
            'blockID' => $nested['bID'],
            'value' => ['content' => $nested['content'] . '<p>Layout edit</p>'],
        ]);

        $this->assertSame($before, $this->approved($cID), 'The approved version must be exactly as it was');
        $this->assertCount(2, $this->versions($cID), 'One draft');
        $draft = $this->draft($cID);
        $this->assertStringContainsString('Layout edit', $this->contentOf($draft['blocks'], $nested['area']));
        $this->assertSame(array_column($before['blocks'], 'area'), array_column($draft['blocks'], 'area'), 'Every block stays in its column');
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

    /**
     * Runs update_page with inputs that must be refused, and checks that nothing was written: the same versions, the
     * same live name, and no draft.
     */
    private function assertUpdatePageRefuses(int $cID, array $inputs, string $expectedMessage, bool $exact): void
    {
        $versionsBefore = $this->versions($cID);
        $liveNameBefore = (string) $this->db()->fetchOne('SELECT cvName FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 1', [$cID]);

        try {
            $this->callPagesTool('update_page', ['pageID' => $cID] + $inputs);
            $this->fail('update_page should have refused ' . json_encode($inputs));
        } catch (\RuntimeException $e) {
            if ($exact) {
                $this->assertSame($expectedMessage, $e->getMessage(), json_encode($inputs));
            } else {
                $this->assertStringContainsString($expectedMessage, $e->getMessage(), json_encode($inputs));
            }
        }

        $this->assertSame($versionsBefore, $this->versions($cID), 'A refused call makes no draft: ' . json_encode($inputs));
        $this->assertSame($liveNameBefore, (string) $this->db()->fetchOne('SELECT cvName FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 1', [$cID]));
    }

    public function testUpdatePageRefusesAnUnknownParameterAndWritesNothing(): void
    {
        $cID = $this->newHeadingsPage();

        // A valid name next to an invented key: the whole call is refused, so the rename is not made either.
        $this->assertUpdatePageRefuses($cID, ['name' => 'Should not be written', 'colour' => 'red'], 'does not accept: colour', false);
        $this->assertUpdatePageRefuses($cID, ['name' => 'Should not be written', 'colour' => 'red'], 'Nothing was changed', false);
    }

    public function testUpdatePageGivesTheMovingMessageForAParentOrPathParameter(): void
    {
        $cID = $this->newHeadingsPage();

        foreach (['parentPath' => '/neuron-test/move-target', 'parentID' => 1, 'newParentID' => 1, 'cParentID' => 1, 'parent' => 1, 'path' => '/x', 'newPath' => '/x', 'destination' => '/x'] as $key => $value) {
            $this->assertUpdatePageRefuses($cID, [$key => $value], PagesToolkit::MOVE_UNAVAILABLE_MESSAGE, true);
        }
        $this->assertSame("Moving pages isn't available yet. It will return with approvals.", PagesToolkit::MOVE_UNAVAILABLE_MESSAGE);
    }

    public function testUpdatePageRefusesAnUnknownAttributeHandleAndWritesNothing(): void
    {
        $cID = $this->newHeadingsPage();

        $this->assertUpdatePageRefuses($cID, ['name' => 'Should not be written', 'attributes' => ['no_such_attribute_handle' => 'x']], "'no_such_attribute_handle' is not a page attribute handle", false);
        $this->assertUpdatePageRefuses($cID, ['attributes' => ['no_such_attribute_handle' => 'x']], 'nothing was changed', false);
    }

    public function testUpdatePageGivesTheMovingMessageForAParentOrPathAttributeKey(): void
    {
        $cID = $this->newHeadingsPage();

        foreach (['parentPath' => '/neuron-test/move-target', 'parent' => 1, 'cParentID' => 1] as $key => $value) {
            $this->assertUpdatePageRefuses($cID, ['attributes' => [$key => $value]], PagesToolkit::MOVE_UNAVAILABLE_MESSAGE, true);
        }
    }

    public function testUpdatePageStillAcceptsARealPageAttribute(): void
    {
        $cID = $this->newHeadingsPage();
        if (!CollectionKey::getByHandle('meta_title')) {
            $this->markTestSkipped('The site has no meta_title page attribute');
        }

        $this->callPagesTool('update_page', ['pageID' => $cID, 'attributes' => ['meta_title' => 'A draft title']]);

        $this->assertCount(2, $this->versions($cID), 'The attribute goes to one draft');
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
