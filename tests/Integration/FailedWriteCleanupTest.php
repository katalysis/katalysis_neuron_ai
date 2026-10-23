<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\AttributesToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;
use Katalysis\NeuronAi\Tools\Utilities\DraftCleanup;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-3: a write tool that fails, is refused or changes nothing leaves the page as it found it (plans/BF-3.md).
 *
 * - **Checked before the draft** (§3): refusals that can be decided on the newest version make no draft at all.
 * - **The clean-up rule** (§4): a call that fails after making a draft discards it only if this call made it, it is
 *   still the newest version, still new, and unchanged and not checked out since it was made. Anything else is kept.
 * - **The retry** (§5): a refused call followed by the corrected one, in the same request, works.
 *
 * Failures inside Concrete are made by test subclasses of the toolkits that throw at a named step after the draft
 * (FaultyPagesToolkit and friends, below). Throwaway pages, deleted afterwards; the leak check covers the rest.
 */
final class FailedWriteCleanupTest extends ConcreteTestCase
{
    /** Block one for the block tools, block two for the headings tool's bold_paragraph match. */
    private const WITH_BOLD_HEADING = ['<p>Block one original text.</p>', '<p><strong>Bold Heading</strong></p>'];

    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
    }

    // ---- The rule: a failure after the draft --------------------------------------------------------------------

    /**
     * Every tool that makes a draft, with inputs that would succeed.
     *
     * @return array<string, array{string, string}> case => [toolkit kind, tool]
     */
    public static function draftToolProvider(): array
    {
        return [
            'batch_update_page_content' => ['pages', 'batch_update_page_content'],
            'batch_update_content_headings' => ['pages', 'batch_update_content_headings'],
            'update_page' => ['pages', 'update_page'],
            'add_block_to_page_area' => ['pages', 'add_block_to_page_area'],
            'update_block_in_page_area' => ['pages', 'update_block_in_page_area'],
            'delete_block_from_page_area' => ['pages', 'delete_block_from_page_area'],
            'move_block_between_areas' => ['pages', 'move_block_between_areas'],
            'set_page_attribute_value, PageAttributesToolkit' => ['page_attributes', 'set_page_attribute_value'],
            'set_page_attribute_value, AttributesToolkit' => ['attributes', 'set_page_attribute_value'],
        ];
    }

    /**
     * Rows A2, D1, F1, G1, H1 of plans/BF-3.md §2: the call fails after making a new draft and before changing it.
     * The draft is discarded, and the same call without the fault then works in the same request (§5, case 2).
     */
    #[DataProvider('draftToolProvider')]
    public function testAFailureAfterANewDraftLeavesNothingAndTheRetryWorks(string $kind, string $tool): void
    {
        $cID = $this->newPage(self::WITH_BOLD_HEADING);
        $before = $this->pages->state($cID);

        $faulty = $this->faulty($kind, afterDraft: static function (): void {
            throw new \RuntimeException('Simulated failure after the draft.');
        });
        $output = $this->runTool($faulty, $tool, $this->workingInputs($tool, $cID));

        $this->assertStringContainsString('Simulated failure after the draft.', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($before, $this->pages->state($cID), "{$tool} left a draft behind after failing.");

        // The retry, with no cache flush in between, as the agent's next call in the same turn.
        $retry = $this->runTool($this->faulty($kind), $tool, $this->workingInputs($tool, $cID), flush: false);
        $this->assertTrue(ToolKind::succeeded($retry), "The retry of {$tool} failed: {$retry}");
        $this->assertCount(2, $this->pages->state($cID)['versions'], 'The retry should make exactly one draft.');
    }

    /**
     * A draft that existed before the call (an earlier call's, or create_page_draft's) is never discarded.
     */
    #[DataProvider('draftToolProvider')]
    public function testAFailureInADraftThatAlreadyExistedLeavesItUntouched(string $kind, string $tool): void
    {
        $cID = $this->newPage(self::WITH_BOLD_HEADING);
        $made = $this->runTool(new VersionsToolkit(), 'create_page_draft', ['pageID' => $cID]);
        $this->assertStringContainsString('Draft Version Created', $made);
        $before = $this->pages->state($cID);

        $faulty = $this->faulty($kind, afterDraft: static function (): void {
            throw new \RuntimeException('Simulated failure after the draft.');
        });
        $output = $this->runTool($faulty, $tool, $this->workingInputs($tool, $cID));

        $this->assertStringContainsString('Simulated failure after the draft.', $output);
        $this->assertSame($before, $this->pages->state($cID), "{$tool} changed or removed a draft it did not make.");
    }

    public function testADraftSomeoneElseChangedAfterItWasMadeIsKept(): void
    {
        $cID = $this->newPage();

        $faulty = $this->faulty('pages', afterDraft: static function (Page $draft): void {
            // Another write (edit mode's block save, or another tab) lands in the new draft.
            $draft->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => '<p>Added by the editor.</p>']);
            throw new \RuntimeException('Simulated failure after the draft.');
        });
        $output = $this->runTool($faulty, 'update_page', ['pageID' => $cID, 'description' => 'Changed by the assistant']);

        $state = $this->pages->state($cID);
        $this->assertCount(2, $state['versions'], 'The draft holding the editor\'s change must be kept.');
        $this->assertStringContainsString('was kept', $output);
        $this->assertStringContainsString('it has changes', $output);
        $this->assertStringNotContainsString('Nothing was changed', $output);
    }

    public function testADraftOpenedInEditModeAfterItWasMadeIsKept(): void
    {
        $cID = $this->newPage();
        $pages = $this->pages;

        $faulty = $this->faulty('pages', afterDraft: static function (Page $draft) use ($pages, $cID): void {
            // The same user opens edit mode on the page now: edit mode has the new draft open.
            $pages->checkOut($cID, ThrowawayPages::actAs('superuser'));
            throw new \RuntimeException('Simulated failure after the draft.');
        });
        $output = $this->runTool($faulty, 'update_page', ['pageID' => $cID, 'description' => 'Changed by the assistant']);

        $this->assertCount(2, $this->pages->state($cID)['versions']);
        $this->assertStringContainsString('was kept', $output);
        $this->assertStringContainsString('open in edit mode', $output);
    }

    public function testADraftThisCallPartlyWroteIsKeptAndNamed(): void
    {
        $cID = $this->newPage();
        [$first] = $this->pages->blockIDs($cID);

        // Row E1: the block is copied into the draft, then the update fails.
        $faulty = $this->faulty('pages', afterCopy: static function (): void {
            throw new \RuntimeException('Simulated failure after the copy.');
        });
        $output = $this->runTool($faulty, 'update_block_in_page_area', $this->workingInputs('update_block_in_page_area', $cID, $first));

        $state = $this->pages->state($cID);
        $this->assertCount(2, $state['versions']);
        $this->assertMatchesRegularExpression('/Version \d+, which this call made, was kept because it has changes/', $output);
    }

    // ---- Checked before the draft ---------------------------------------------------------------------------

    public function testTheHeadingsToolMakesNoDraftWhenNoHeadingMatches(): void
    {
        $cID = $this->newPage(['<h3>Only Heading</h3><p>Body.</p>']);
        $before = $this->pages->state($cID);

        $output = $this->runTool(new PagesToolkit(), 'batch_update_content_headings', [
            'pageID' => $cID, 'headingTexts' => ['No Such Heading'], 'targetTag' => 'h2',
        ]);

        $this->assertSame(0, $this->json($output)['summary']['updatedCount']);
        $this->assertSame($before, $this->pages->state($cID), 'A headings call that changed nothing left a draft.');
    }

    public function testUpdatePageWithNothingToChangeIsRefusedWithoutADraft(): void
    {
        $cID = $this->newPage();
        $before = $this->pages->state($cID);

        $output = $this->runTool(new PagesToolkit(), 'update_page', ['pageID' => $cID]);

        $this->assertStringContainsString('No change was given', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($before, $this->pages->state($cID));

        $retry = $this->runTool(new PagesToolkit(), 'update_page', ['pageID' => $cID, 'name' => 'Renamed by the assistant'], flush: false);
        $this->assertTrue(ToolKind::succeeded($retry), $retry);
    }

    // ---- batch_update_page_content: per-block changeMode, checks before the draft --------------------------------

    /**
     * Scenario 05 runs 3 and 4 in BF-5: changeMode and replacedText inside the block item. The item's mode is used.
     */
    public function testAnItemsOwnChangeModeIsUsed(): void
    {
        $cID = $this->newPage();
        [$first] = $this->pages->blockIDs($cID);

        $output = $this->runTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [[
                'areaHandle' => 'Main', 'blockID' => $first, 'value' => ['content' => '<p>Block one rewritten.</p>'],
                'changeMode' => 'scoped_text', 'textFields' => ['content'], 'replacedText' => ['Block one original text.'],
            ]],
        ]);

        $result = $this->json($output);
        $this->assertSame(1, $result['summary']['successCount'], $output);
        $this->assertSame('scoped_text', $result['blocks'][0]['changeMode']);
        $this->assertStringContainsString('Block one rewritten.', $this->newestContent($cID));
    }

    /**
     * Row A1 and the retry (§5, case 1): a refused item is never copied, so its block ID still works.
     */
    public function testARefusedBatchLeavesNoDraftAndTheCorrectedRetryWorks(): void
    {
        $cID = $this->newPage();
        [$first] = $this->pages->blockIDs($cID);
        $before = $this->pages->state($cID);

        // keep_text (the default) refuses losing the old text.
        $refused = $this->runTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [['areaHandle' => 'Main', 'blockID' => $first, 'value' => ['content' => '<p>Block one rewritten.</p>']]],
        ]);
        $result = $this->json($refused);
        $this->assertSame(0, $result['summary']['successCount']);
        $this->assertFalse($result['blocks'][0]['ok']);
        $this->assertStringContainsString('Nothing was changed', $refused);
        $this->assertArrayNotHasKey('versionState', $result);
        $this->assertSame($before, $this->pages->state($cID), 'A refused batch left a draft or copied a block.');

        $retry = $this->runTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'changeMode' => 'scoped_text',
            'blocks' => [[
                'areaHandle' => 'Main', 'blockID' => $first, 'value' => ['content' => '<p>Block one rewritten.</p>'],
                'textFields' => ['content'], 'replacedText' => ['Block one original text.'],
            ]],
        ], flush: false);
        $this->assertSame(1, $this->json($retry)['summary']['successCount'], $retry);
    }

    public function testAnInvalidItemChangeModeIsRefusedByIndexBeforeAnyDraft(): void
    {
        $cID = $this->newPage();
        [$first] = $this->pages->blockIDs($cID);
        $before = $this->pages->state($cID);

        $output = $this->runTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [['areaHandle' => 'Main', 'blockID' => $first, 'value' => ['content' => '<h2>Block one original text.</h2>'], 'changeMode' => 'freeform']],
        ]);

        $this->assertStringContainsString('blocks[0].changeMode must be one of: keep_text, formatting_only, scoped_text', $output);
        $this->assertSame($before, $this->pages->state($cID));
    }

    public function testReplacedTextOnAnItemThatIsNotScopedIsRefusedWithTheFix(): void
    {
        $cID = $this->newPage();
        [$first] = $this->pages->blockIDs($cID);
        $before = $this->pages->state($cID);

        $output = $this->runTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [[
                'areaHandle' => 'Main', 'blockID' => $first,
                'value' => ['content' => '<p>Block one original text.</p><p>Added.</p>'],
                'replacedText' => ['Block one original text.'],
            ]],
        ]);

        $result = $this->json($output);
        $this->assertFalse($result['blocks'][0]['ok']);
        $this->assertStringContainsString('replacedText', $result['blocks'][0]['error']);
        $this->assertStringContainsString('scoped_text', $result['blocks'][0]['error']);
        $this->assertSame($before, $this->pages->state($cID));
    }

    public function testAMixedBatchAppliesOnlyTheItemsThatPassAndNeverCopiesTheOthers(): void
    {
        $cID = $this->newPage();
        [$first, $second] = $this->pages->blockIDs($cID);

        $output = $this->runTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [
                ['areaHandle' => 'Main', 'blockID' => $first, 'value' => ['content' => '<h2>Block one original text.</h2>'], 'changeMode' => 'formatting_only'],
                ['areaHandle' => 'Main', 'blockID' => $second, 'value' => ['content' => '<p>Block two rewritten.</p>']],
            ],
        ]);

        $result = $this->json($output);
        $this->assertSame(1, $result['summary']['successCount'], $output);
        $this->assertTrue($result['blocks'][0]['ok']);
        $this->assertFalse($result['blocks'][1]['ok']);
        $this->assertContains($second, $this->pages->blockIDs($cID), 'The refused block was copied into the draft under a new ID.');
    }

    public function testWithContinueOnErrorFalseOneRefusedItemMeansNothingIsApplied(): void
    {
        $cID = $this->newPage();
        [$first, $second] = $this->pages->blockIDs($cID);
        $before = $this->pages->state($cID);

        $output = $this->runTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'continueOnError' => false,
            'blocks' => [
                ['areaHandle' => 'Main', 'blockID' => $first, 'value' => ['content' => '<h2>Block one original text.</h2>'], 'changeMode' => 'formatting_only'],
                ['areaHandle' => 'Main', 'blockID' => $second, 'value' => ['content' => '<p>Block two rewritten.</p>']],
            ],
        ]);

        $result = $this->json($output);
        $this->assertSame(0, $result['summary']['successCount'], $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($before, $this->pages->state($cID));
    }

    // ---- create_page ----------------------------------------------------------------------------------------

    public function testCreatePageRefusesAnUnknownAttributeHandleBeforeMakingThePage(): void
    {
        $name = 'BF-3 unknown attribute ' . bin2hex(random_bytes(3));
        $output = $this->runTool(new PagesToolkit(), 'create_page', [
            'parentID' => $this->parentID(), 'name' => $name, 'attributes' => ['no_such_handle_bf3' => 'x'],
        ]);
        $made = $this->pagesNamed($name);
        array_map([$this->pages, 'adopt'], $made);

        $this->assertSame([], $made, 'create_page made the page before refusing the unknown handle.');
        $this->assertStringContainsString("'no_such_handle_bf3' is not a page attribute handle", $output);
        $this->assertStringContainsStringIgnoringCase('nothing was changed', $output);
    }

    /**
     * Row I1: create_page fails after making the page, with an attribute already set by this call. The page goes, and
     * so does every row it had.
     */
    public function testCreatePageDeletesThePageItMadeWhenItFailsAfterwards(): void
    {
        $name = 'BF-3 failed create ' . bin2hex(random_bytes(3));
        $made = null;
        $faulty = new class extends PagesToolkit {
            public ?\Closure $onSerialize = null;

            protected function serializePage(Page $page, bool $includeDetails = false): array
            {
                ($this->onSerialize)($page);

                return parent::serializePage($page, $includeDetails);
            }
        };
        $faulty->onSerialize = static function (Page $page) use (&$made): void {
            $made = (int) $page->getCollectionID();
            throw new \RuntimeException('Simulated failure after the page was made.');
        };

        $output = $this->runTool($faulty, 'create_page', [
            'parentID' => $this->parentID(), 'name' => $name, 'attributes' => ['meta_title' => 'Set by the assistant'],
        ]);
        $this->assertIsInt($made, 'The fault never ran: ' . $output);
        array_map([$this->pages, 'adopt'], $this->pagesNamed($name));

        $this->assertStringContainsString('Simulated failure after the page was made.', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        foreach (['Pages', 'Collections', 'CollectionVersions', 'CollectionVersionBlocks', 'CollectionAttributeValues', 'PagePaths', 'PageSummaryTemplates', 'Areas'] as $table) {
            $this->assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM {$table} WHERE cID = ?", [$made]), "{$table} still has rows for the page create_page made.");
        }
    }

    // ---- Registry ----------------------------------------------------------------------------------------------

    /**
     * Every toolkit that makes a draft runs its tools through DraftCleanup, and every write tool that takes a page is
     * covered above or exempt with a reason.
     */
    public function testEveryDraftMakingWriteToolIsCovered(): void
    {
        $exempt = [
            'create_page' => 'makes a page, not a draft: testCreatePageDeletesThePageItMadeWhenItFailsAfterwards',
            'move_page' => 'disabled; moves the live page, no draft (P1.13)',
            'delete_page' => 'disabled; trashes the live page, no draft (P1.13)',
            'sync_multi_links_to_epra_resources_list' => 'disabled, EPRA only, moves out in P3.4',
        ];
        $covered = array_map(static fn (array $case): string => $case[1], array_values(self::draftToolProvider()));

        $found = [];
        foreach ([new PagesToolkit(), new PageAttributesToolkit(), new AttributesToolkit()] as $toolkit) {
            $source = (string) file_get_contents((new \ReflectionClass($toolkit))->getFileName());
            if (preg_match('/PageDraft::of\(|getDraftOf\(/', $source) === 1) {
                $this->assertStringContainsString('DraftCleanup::run(', $source, $toolkit::class . ' makes drafts but its tools do not run through DraftCleanup.');
            }
            foreach ($toolkit->tools() as $tool) {
                if (!ToolKind::isWrite($tool->getName())) {
                    continue;
                }
                foreach ($tool->getProperties() as $property) {
                    if (in_array($property->getName(), ['pageID', 'parentID'], true)) {
                        $found[] = $tool->getName();
                    }
                }
            }
        }

        $expected = array_values(array_unique(array_merge($covered, array_keys($exempt))));
        $found = array_values(array_unique($found));
        sort($expected);
        sort($found);
        $this->assertSame($found, $expected, 'A write tool takes a page but no BF-3 case covers what it leaves when it fails.');
    }

    /**
     * The fingerprint covers every table keyed by a page version (cID and cvID), so a Concrete upgrade that adds one
     * fails here rather than letting a used version look unused (plans/BF-3.md §10).
     */
    public function testTheFingerprintCoversEveryTableKeyedByAVersion(): void
    {
        $tables = $this->db()->fetchFirstColumn(
            "SELECT c1.TABLE_NAME FROM information_schema.COLUMNS c1
             JOIN information_schema.COLUMNS c2 ON c2.TABLE_SCHEMA = c1.TABLE_SCHEMA AND c2.TABLE_NAME = c1.TABLE_NAME AND c2.COLUMN_NAME = 'cvID'
             WHERE c1.TABLE_SCHEMA = DATABASE() AND c1.COLUMN_NAME = 'cID' ORDER BY 1"
        );
        $known = array_merge(DraftCleanup::VERSION_TABLES, array_keys(DraftCleanup::NOT_VERSION_CONTENT));
        sort($known);

        $this->assertSame($tables, $known, 'A table keyed by a page version is neither in the fingerprint nor listed as not part of a version.');
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    /**
     * @param list<string>|null $contents
     */
    private function newPage(?array $contents = null): int
    {
        $page = $contents === null ? $this->pages->create('BF-3') : $this->pages->create('BF-3', $contents);

        return (int) $page->getCollectionID();
    }

    private function parentID(): int
    {
        ThrowawayPages::actAs('superuser');

        return (int) Page::getByPath('/neuron-test')->getCollectionID();
    }

    /**
     * A toolkit whose draft step, or block copy, runs a hook first (the hook may throw).
     */
    private function faulty(string $kind, ?\Closure $afterDraft = null, ?\Closure $afterCopy = null): ToolkitInterface
    {
        $toolkit = match ($kind) {
            'pages' => new class extends PagesToolkit {
                public ?\Closure $afterDraft = null;
                public ?\Closure $afterCopy = null;

                protected function getDraftOf(Page $page): Page
                {
                    $draft = parent::getDraftOf($page);
                    if ($this->afterDraft !== null) {
                        ($this->afterDraft)($draft);
                    }

                    return $draft;
                }

                public function getBlockToEdit(Page $page, \Concrete\Core\Area\Area $area, string $areaHandle, $blockID): ?Block
                {
                    $block = parent::getBlockToEdit($page, $area, $areaHandle, $blockID);
                    if ($this->afterCopy !== null) {
                        ($this->afterCopy)($block);
                    }

                    return $block;
                }
            },
            'page_attributes' => new class extends PageAttributesToolkit {
                public ?\Closure $afterDraft = null;
                public ?\Closure $afterCopy = null;

                protected function getDraftOf(Page $page): Page
                {
                    $draft = parent::getDraftOf($page);
                    if ($this->afterDraft !== null) {
                        ($this->afterDraft)($draft);
                    }

                    return $draft;
                }
            },
            'attributes' => new class extends AttributesToolkit {
                public ?\Closure $afterDraft = null;
                public ?\Closure $afterCopy = null;

                protected function getDraftOf(Page $page): Page
                {
                    $draft = parent::getDraftOf($page);
                    if ($this->afterDraft !== null) {
                        ($this->afterDraft)($draft);
                    }

                    return $draft;
                }
            },
        };
        $toolkit->afterDraft = $afterDraft;
        $toolkit->afterCopy = $afterCopy;

        return $toolkit;
    }

    /**
     * Inputs for a write that succeeds on a throwaway page (block one, or $blockID).
     *
     * @return array<string, mixed>
     */
    private function workingInputs(string $tool, int $cID, ?int $blockID = null): array
    {
        $blockID ??= $this->pages->blockIDs($cID)[0];
        $html = (string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$blockID]);
        $asHeading = (string) preg_replace('/^<p>(.*)<\/p>$/s', '<h2>$1</h2>', trim($html));
        ThrowawayPages::actAs('superuser');

        return match ($tool) {
            'batch_update_page_content' => ['pageID' => $cID, 'changeMode' => 'formatting_only', 'blocks' => [['areaHandle' => 'Main', 'blockID' => $blockID, 'value' => ['content' => $asHeading]]]],
            'batch_update_content_headings' => ['pageID' => $cID, 'matchMode' => 'bold_paragraph', 'targetTag' => 'h3'],
            'update_page' => ['pageID' => $cID, 'description' => 'Changed by the assistant'],
            'add_block_to_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'content', 'value' => ['content' => '<p>A new block.</p>']],
            'update_block_in_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $blockID, 'value' => ['content' => $asHeading], 'changeMode' => 'formatting_only'],
            'delete_block_from_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $blockID],
            'move_block_between_areas' => ['pageID' => $cID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => $blockID],
            'set_page_attribute_value' => ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Changed by the assistant'],
        };
    }

    /**
     * Runs a tool as the agent would. $flush: false keeps the request's page cache, as between two calls in one turn.
     */
    private function runTool(ToolkitInterface $toolkit, string $name, array $inputs, bool $flush = true): string
    {
        if ($flush) {
            CacheLocal::flush();
        }
        foreach ($toolkit->tools() as $tool) {
            if ($tool->getName() !== $name) {
                continue;
            }
            $tool = clone $tool;
            try {
                $tool->setInputs($inputs)->execute();
                $result = $tool->getResult();

                return is_string($result) ? $result : $result->getText();
            } catch (\Throwable $e) {
                return 'Error executing tool: ' . $e->getMessage();
            }
        }
        $this->fail("No tool named {$name} in " . $toolkit::class);
    }

    /** @return array<string, mixed> */
    private function json(string $output): array
    {
        $data = json_decode($output, true);
        $this->assertIsArray($data, "Not a JSON result:\n{$output}");

        return $data;
    }

    private function newestContent(int $cID): string
    {
        return implode("\n", array_map(
            fn (int $bID): string => (string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$bID]),
            $this->pages->blockIDs($cID)
        ));
    }

    /** @return list<int> */
    private function pagesNamed(string $name): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn('SELECT DISTINCT cID FROM CollectionVersions WHERE cvName = ?', [$name]));
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
