<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Approval\CallCheck;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\GlobalAreaFixture;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\AttributeSetsToolkit;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\FileAttributesToolkit;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\PeopleToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;
use Katalysis\NeuronAi\Tools\TopicsToolkit;
use Katalysis\NeuronAi\Tools\UserAttributesToolkit;
use Katalysis\NeuronAi\Tools\UsersToolkit;
use Katalysis\NeuronAi\Tools\Utilities\DraftCleanup;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use Katalysis\NeuronAi\Tools\Utilities\SharedArea;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P3.6a (plans/P3.6a.md), B-WRT-5 (b): the block tools edit a global area the way edit mode does. The change goes into
 * an unapproved version of the area's stack, related to the page's draft, so it reaches the site only when the page
 * draft is published (Concrete publishes the stack version with it). The live stack and every other page that shows
 * the area stay as they were, the result says the change is shared and on how many pages, and a user without the
 * stack's own permissions is refused. Before P3.6a (BF-2) every such call was refused.
 *
 * A throwaway global area and pages from GlobalAreaFixture: page A, where the tool runs, and page B, which shows the
 * same area. As the superuser, except for the editor cases.
 */
final class GlobalAreaWriteTest extends ConcreteTestCase
{
    /** Inputs that name an area, or carry entries that do. */
    private const AREA_INPUTS = ['areaHandle', 'fromAreaHandle', 'toAreaHandle', 'blocks'];

    /** Area inputs that only filter what a tool reads from the page's own blocks (see the headings test). */
    private const AREA_FILTERS = ['areaHandleContains'];

    private GlobalAreaFixture $fixture;

    protected function setUp(): void
    {
        $this->actAs('superuser');
        $this->fixture = new GlobalAreaFixture($this->db());
    }

    protected function tearDown(): void
    {
        $this->actAs('superuser');
        $this->fixture->restore();
        Request::getInstance()->setCustomRequestUser(-1);
    }

    /**
     * @return array<string, array{string, string}> case => [tool, area input]
     */
    public static function blockToolProvider(): array
    {
        return [
            'update_block_in_page_area' => ['update_block_in_page_area', 'areaHandle'],
            'delete_block_from_page_area' => ['delete_block_from_page_area', 'areaHandle'],
            'add_block_to_page_area' => ['add_block_to_page_area', 'areaHandle'],
            'move_block_between_areas (from the global area)' => ['move_block_between_areas', 'fromAreaHandle'],
            'move_block_between_areas (into the global area)' => ['move_block_between_areas', 'toAreaHandle'],
        ];
    }

    #[DataProvider('blockToolProvider')]
    public function testTheToolWritesAStackDraftTiedToThePageDraft(string $tool, string $areaInput): void
    {
        $setup = $this->setUpPages();
        $liveStack = $this->liveBlocks($setup['stackID']);
        $pageB = $this->fixture->versions($setup['pageB']);

        $output = ThrowawayPages::runTool(new ContentToolkit(), $tool, $this->inputs($tool, $areaInput, $setup));

        $this->assertTrue(ToolKind::succeeded($output), "{$tool} failed: {$output}");
        $stackDraft = $this->newestVersion($setup['stackID']);
        $pageDraft = $this->newestVersion($setup['pageA']);
        $this->assertSame(0, $stackDraft['approved'], "{$tool}: the change is in an unapproved stack version");
        $this->assertSame(0, $pageDraft['approved'], "{$tool}: page A has a draft");
        $this->assertSame(1, (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM CollectionVersionRelatedEdits WHERE cID = ? AND cvID = ? AND cRelationID = ? AND cvRelationID = ?',
            [$setup['pageA'], $pageDraft['cvID'], $setup['stackID'], $stackDraft['cvID']]
        ), "{$tool}: the stack version is related to page A's draft, so publishing the page publishes it");
        $this->assertSame($liveStack, $this->liveBlocks($setup['stackID']), "{$tool}: the live stack is unchanged");
        $this->assertSame($pageB, $this->fixture->versions($setup['pageB']), "{$tool}: page B is unchanged");

        $stackBlocks = $this->blocksIn($setup['stackID'], $stackDraft['cvID']);
        $pageBlocks = $this->blocksIn($setup['pageA'], $pageDraft['cvID']);
        match ($tool . '/' . $areaInput) {
            'update_block_in_page_area/areaHandle' => $this->assertStringContainsString('Changed by the tool', $this->contentOf($stackBlocks)),
            'delete_block_from_page_area/areaHandle' => $this->assertStringNotContainsString('Original global content', $this->contentOf($stackBlocks)),
            'add_block_to_page_area/areaHandle' => $this->assertStringContainsString('Added by the tool', $this->contentOf($stackBlocks)),
            'move_block_between_areas/fromAreaHandle' => [
                $this->assertStringNotContainsString('Original global content', $this->contentOf($stackBlocks)),
                $this->assertStringContainsString('Original global content', $this->contentOf($pageBlocks)),
            ],
            'move_block_between_areas/toAreaHandle' => [
                $this->assertStringContainsString('Page block', $this->contentOf($stackBlocks)),
                $this->assertStringNotContainsString('Page block', $this->contentOf($pageBlocks)),
            ],
        };

        $shared = json_decode($output, true)['sharedArea'] ?? null;
        $this->assertIsArray($shared, "{$tool}: the result says the area is shared: {$output}");
        $this->assertSame($setup['handle'], $shared['name']);
        $this->assertSame('global area', $shared['kind']);
        $this->assertSame(2, $shared['pages'], 'pages A and B show it');
        $this->assertStringContainsString('every page', $shared['notice']);
    }

    public function testPublishingThePageDraftPublishesTheStackAndDiscardingItRemovesIt(): void
    {
        $setup = $this->setUpPages();
        $inputs = $this->inputs('update_block_in_page_area', 'areaHandle', $setup);
        $this->assertTrue(ToolKind::succeeded(ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', $inputs)));

        CacheLocal::flush();
        $draft = Page::getByID($setup['pageA'], 'RECENT');
        $draft->getVersionObject()->approve();
        CacheLocal::flush();
        $this->assertStringContainsString('Changed by the tool', $this->contentOf($this->liveBlocks($setup['stackID'])), 'published with the page');

        $inputs['value'] = ['content' => '<p>Original global content</p><p>Changed by the tool</p><p>Changed again</p>'];
        $inputs['blockID'] = (int) $this->db()->fetchOne(
            'SELECT bID FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? LIMIT 1',
            [$setup['stackID'], $this->newestVersion($setup['stackID'])['cvID']]
        );
        $this->assertTrue(ToolKind::succeeded(ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', $inputs)));
        $stackDraft = $this->newestVersion($setup['stackID']);
        $this->assertSame(0, $stackDraft['approved']);

        CacheLocal::flush();
        $pageDraft = Page::getByID($setup['pageA'], 'RECENT');
        $pageDraft->getVersionObject()->discard();
        CacheLocal::flush();
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ? AND cvID = ?', [$setup['stackID'], $stackDraft['cvID']]), 'discarded with the page draft');
    }

    public function testAStackPageIsEditedDirectlyInAStackDraftWithNoPage(): void
    {
        [$handle, $stackID, $stackBlockID] = $this->fixture->globalArea();
        $this->fixture->page([$handle]);
        $liveStack = $this->liveBlocks($stackID);

        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $stackID, 'areaHandle' => 'Main', 'blockID' => $stackBlockID, 'value' => ['content' => '<p>Original global content</p><p>Changed in the stack</p>'],
        ]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $stackDraft = $this->newestVersion($stackID);
        $this->assertSame(0, $stackDraft['approved']);
        $this->assertStringContainsString('Changed in the stack', $this->contentOf($this->blocksIn($stackID, $stackDraft['cvID'])));
        $this->assertSame($liveStack, $this->liveBlocks($stackID));
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersionRelatedEdits WHERE cRelationID = ?', [$stackID]), 'no page draft');
        $shared = json_decode($output, true)['sharedArea'];
        $this->assertSame($handle, $shared['name']);
        $this->assertSame(1, $shared['pages']);
        $this->assertStringContainsString('Stacks & Global Areas', $shared['notice']);
    }

    #[DataProvider('blockToolProvider')]
    public function testTheApprovalDryRunMakesNothing(string $tool, string $areaInput): void
    {
        $setup = $this->setUpPages();
        $stack = $this->fixture->versions($setup['stackID']);
        $pageA = $this->fixture->versions($setup['pageA']);

        $this->assertNull(CallCheck::dryRun($tool, $this->inputs($tool, $areaInput, $setup)), "{$tool}: the call passes its checks");

        $this->assertSame($stack, $this->fixture->versions($setup['stackID']), "{$tool}: the dry run made a stack version");
        $this->assertSame($pageA, $this->fixture->versions($setup['pageA']), "{$tool}: the dry run made a page draft");
    }

    /**
     * Glyn, after BF-2: whether Concrete's own permissions stop neuron_editor, who may edit page A but not the stack.
     * The explicit stack checks (P3.6a) refuse it either way, naming the stack and the permission.
     */
    #[DataProvider('blockToolProvider')]
    public function testAnEditorWithoutStackPermissionIsRefused(string $tool, string $areaInput): void
    {
        $setup = $this->setUpPages();
        $stack = $this->fixture->versions($setup['stackID']);
        $pageA = $this->fixture->versions($setup['pageA']);
        $this->actAs('neuron_editor');

        $output = ThrowawayPages::runTool(new ContentToolkit(), $tool, $this->inputs($tool, $areaInput, $setup));

        $this->assertFalse(ToolKind::succeeded($output), "{$tool} let neuron_editor change a stack it can't edit: {$output}");
        $this->assertStringContainsString('Permission denied', $output);
        $this->assertStringContainsString($setup['handle'], $output, 'the refusal names the global area');
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->actAs('superuser');
        $this->assertSame($stack, $this->fixture->versions($setup['stackID']), "{$tool} changed the stack");
        $this->assertSame($pageA, $this->fixture->versions($setup['pageA']), "{$tool} left a draft on page A");
    }

    /** BF-3's rule for a stack: a call that fails after making the stack draft and the page draft leaves neither. */
    public function testAFailedCallLeavesNoStackVersionAndNoPageDraft(): void
    {
        $setup = $this->setUpPages();
        $stack = $this->fixture->versions($setup['stackID']);
        $pageA = $this->fixture->versions($setup['pageA']);

        try {
            DraftCleanup::run(static function () use ($setup): void {
                $page = Page::getByID($setup['pageA'], 'RECENT');
                SharedArea::of($page, $setup['handle'])->versionToModify(PageDraft::of($page));
                throw new \RuntimeException('Failed after the drafts.');
            });
            $this->fail('The call should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed after the drafts.', $e->getMessage());
        }

        CacheLocal::flush();
        $this->assertSame($stack, $this->fixture->versions($setup['stackID']), 'the stack version was removed');
        $this->assertSame($pageA, $this->fixture->versions($setup['pageA']), 'the page draft was removed');
    }

    public function testTheBatchToolStillRefusesAGlobalArea(): void
    {
        $setup = $this->setUpPages();
        $stack = $this->fixture->versions($setup['stackID']);
        $pageA = $this->fixture->versions($setup['pageA']);

        $output = ThrowawayPages::runTool(new BatchEditToolkit(), 'batch_update_page_content', [
            'pageID' => $setup['pageA'],
            'blocks' => [['areaHandle' => $setup['handle'], 'blockID' => $setup['stackBlockID'], 'value' => ['content' => '<p>Batch</p>']]],
        ]);

        $this->assertStringContainsString('update_block_in_page_area', $output, $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($stack, $this->fixture->versions($setup['stackID']));
        $this->assertSame($pageA, $this->fixture->versions($setup['pageA']));
    }

    public function testHeadingUpdatesNeverReachAGlobalArea(): void
    {
        [$handle, $stackID] = $this->fixture->globalArea('<h3>Global Heading</h3><p>Shared text.</p>');
        $page = $this->fixture->page([$handle]);
        $stackBefore = $this->fixture->versions($stackID);

        ThrowawayPages::runTool(new BatchEditToolkit(), 'batch_update_content_headings', [
            'pageID' => (int) $page->getCollectionID(),
            'headingTexts' => ['Global Heading'],
            'targetTag' => 'h2',
        ]);

        $this->assertSame($stackBefore, $this->fixture->versions($stackID), 'batch_update_content_headings changed a global area.');
    }

    public function testEveryWriteToolWithAnAreaInputIsCovered(): void
    {
        $covered = ['batch_update_page_content' => ['blocks']];
        foreach (self::blockToolProvider() as [$tool, $input]) {
            $covered[$tool][] = $input;
        }

        $found = [];
        foreach ($this->allToolkits() as $toolkit) {
            foreach ($toolkit->tools() as $tool) {
                if (!$tool instanceof ToolInterface || !ToolKind::isWrite($tool->getName())) {
                    continue;
                }
                foreach ($tool->getProperties() as $property) {
                    $name = $property->getName();
                    if (in_array($name, self::AREA_FILTERS, true)) {
                        continue;
                    }
                    if (in_array($name, self::AREA_INPUTS, true) || preg_match('/area/i', $name) === 1) {
                        $found[$tool->getName()][] = $name;
                    }
                }
            }
        }

        ksort($covered);
        ksort($found);
        foreach ($covered as &$inputs) {
            $inputs = array_values(array_unique($inputs));
        }
        $this->assertSame($found, $covered, 'A write tool takes an area but is not in blockToolProvider(), so nothing checks what it does to a '
            . 'global area. Add a case, or list a read-only filter in AREA_FILTERS.');
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /**
     * A global area with one block, page A (where the tools run, with its own block in Main) and page B, both showing it.
     *
     * @return array{handle: string, stackID: int, stackBlockID: int, pageA: int, pageB: int, pageBlockID: int}
     */
    private function setUpPages(): array
    {
        [$handle, $stackID, $stackBlockID] = $this->fixture->globalArea();
        $pageA = $this->fixture->page([$handle]);
        $pageBlockID = (int) $pageA->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => '<p>Page block</p>'])->getBlockID();
        $pageB = $this->fixture->page([$handle]);
        CacheLocal::flush();

        return [
            'handle' => $handle,
            'stackID' => $stackID,
            'stackBlockID' => $stackBlockID,
            'pageA' => (int) $pageA->getCollectionID(),
            'pageB' => (int) $pageB->getCollectionID(),
            'pageBlockID' => $pageBlockID,
        ];
    }

    /** @return array<string, mixed> */
    private function inputs(string $tool, string $areaInput, array $setup): array
    {
        return match ($areaInput) {
            'areaHandle' => match ($tool) {
                'add_block_to_page_area' => ['pageID' => $setup['pageA'], 'areaHandle' => $setup['handle'], 'blockType' => 'content', 'value' => ['content' => '<p>Added by the tool</p>']],
                'update_block_in_page_area' => ['pageID' => $setup['pageA'], 'areaHandle' => $setup['handle'], 'blockID' => $setup['stackBlockID'], 'value' => ['content' => '<p>Original global content</p><p>Changed by the tool</p>']],
                default => ['pageID' => $setup['pageA'], 'areaHandle' => $setup['handle'], 'blockID' => $setup['stackBlockID']],
            },
            'fromAreaHandle' => ['pageID' => $setup['pageA'], 'fromAreaHandle' => $setup['handle'], 'toAreaHandle' => 'Main', 'blockID' => $setup['stackBlockID']],
            'toAreaHandle' => ['pageID' => $setup['pageA'], 'fromAreaHandle' => 'Main', 'toAreaHandle' => $setup['handle'], 'blockID' => $setup['pageBlockID']],
        };
    }

    /** @return array{cvID: int, approved: int} */
    private function newestVersion(int $cID): array
    {
        $row = $this->db()->fetchAssociative('SELECT cvID, cvIsApproved FROM CollectionVersions WHERE cID = ? ORDER BY cvID DESC LIMIT 1', [$cID]);

        return ['cvID' => (int) $row['cvID'], 'approved' => (int) $row['cvIsApproved']];
    }

    /** @return list<int> */
    private function blocksIn(int $cID, int $cvID): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn('SELECT bID FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? ORDER BY bID', [$cID, $cvID]));
    }

    /** @return list<int> */
    private function liveBlocks(int $cID): array
    {
        $cvID = (int) $this->db()->fetchOne('SELECT cvID FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 1', [$cID]);

        return $this->blocksIn($cID, $cvID);
    }

    /** The Content blocks' text, together. */
    private function contentOf(array $bIDs): string
    {
        if ($bIDs === []) {
            return '';
        }

        return implode("\n", $this->db()->fetchFirstColumn(
            'SELECT content FROM btContentLocal WHERE bID IN (' . implode(',', array_map('intval', $bIDs)) . ')'
        ));
    }

    /**
     * @return list<ToolkitInterface>
     */
    private function allToolkits(): array
    {
        return [
            new PagesToolkit(), new ContentToolkit(), new BatchEditToolkit(), new BlocksToolkit(), new FilesToolkit(), new UsersToolkit(), new PageAttributesToolkit(),
            new UserAttributesToolkit(), new FileAttributesToolkit(), new AttributeSetsToolkit(), new TopicsToolkit(),
            new PeopleToolkit(), new VersionsToolkit(),
        ];
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
        $this->app()->make('cache/request')->flush();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
