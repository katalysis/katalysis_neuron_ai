<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * BF-17 (P3.S1's regression): the block write tools refuse a field the block type doesn't have, before any draft, and
 * name the type's real fields. In P3.S1, scenario 30's model sent `description`, then `heading` and `content`, for a
 * Feature block (its fields are `title` and `paragraph`); `add_block_to_page_area` dropped them, saved an empty block
 * and reported success. 07's one failed run sent `html` to an HTML block (its field is `content`).
 *
 * Inputs a block's own save() takes that are not columns (the link picker's `link__which`, the accordion's entries)
 * are still accepted. No AI provider call.
 */
final class UnknownBlockFieldsTest extends ConcreteTestCase
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
    }

    public function testAddingABlockWithAFieldItDoesNotHaveIsRefusedBeforeAnyDraft(): void
    {
        [$cID] = $this->pageWith('feature', ['title' => 'Reading Room', 'paragraph' => '<p>Desks.</p>', 'link__which' => 'none']);
        $versions = $this->versionCount($cID);

        // Scenario 30, run 3.
        $output = ThrowawayPages::runTool(new ContentToolkit(), 'add_block_to_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'feature',
            'value' => ['heading' => 'Study Space', 'content' => 'Quiet desks on the first floor.'],
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('heading', $output);
        $this->assertStringContainsString('content', $output);
        $this->assertStringContainsString('title', $output, 'the message names the real fields');
        $this->assertStringContainsString('paragraph', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($versions, $this->versionCount($cID), 'no draft was made');
    }

    public function testTheRefusalTellsTheModelToReadABlockOfThatTypeFirstAndTryAgain(): void
    {
        // P3.12 (Glyn, after 31 in P3.S4): the model guessed 'entries' for a new Accordion, got this refusal and gave
        // up. The refusal now says what to do next: read an existing block of that type, then send it again.
        [$cID] = $this->pageWith('feature', ['title' => 'Reading Room', 'paragraph' => '<p>Desks.</p>', 'link__which' => 'none']);

        $output = ThrowawayPages::runTool(new ContentToolkit(), 'add_block_to_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'feature',
            'value' => ['heading' => 'Study Space'],
        ]);

        $this->assertStringContainsString('read an existing feature block with get_block_data first', $output);
        $this->assertStringContainsString('then send the change again with its field names', $output);
    }

    public function testUpdatingABlockWithAFieldItDoesNotHaveIsRefusedBeforeAnyDraft(): void
    {
        [$cID, $bID] = $this->pageWith('feature', ['title' => 'Reading Room', 'paragraph' => '<p>Desks.</p>', 'link__which' => 'none']);
        $versions = $this->versionCount($cID);

        // Scenario 30, run 2's field name, on an update.
        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['description' => 'Quiet desks.'],
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('description', $output);
        $this->assertStringContainsString('paragraph', $output, 'the message names the real fields');
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($versions, $this->versionCount($cID), 'no draft was made');
    }

    public function testTheBatchToolRefusesAnItemWithAFieldTheBlockDoesNotHaveBeforeAnyDraft(): void
    {
        [$cID, $bID] = $this->pageWith('html', ['content' => '<p>Old HTML.</p>']);
        $versions = $this->versionCount($cID);

        // Scenario 07, run 2's field name for an HTML block.
        $output = ThrowawayPages::runTool(new BatchEditToolkit(), 'batch_update_page_content', [
            'pageID' => $cID, 'blocks' => [['areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['html' => '<p>New HTML.</p>']]],
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString("'html'", $output);
        $this->assertStringContainsString('content', $output, 'the message names the real field');
        $this->assertSame($versions, $this->versionCount($cID), 'no draft was made');
    }

    public function testSaveInputsThatAreNotColumnsAreStillAccepted(): void
    {
        [$cID] = $this->pageWith('feature', ['title' => 'Reading Room', 'paragraph' => '<p>Desks.</p>', 'link__which' => 'none']);

        // Concrete's destination picker input, which the Feature block's save() reads.
        $output = ThrowawayPages::runTool(new ContentToolkit(), 'add_block_to_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'feature',
            'value' => ['title' => 'Study Space', 'paragraph' => '<p>Quiet desks.</p>', 'link__which' => 'none'],
        ]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    /**
     * @return array{0: int, 1: int} the page ID and the block's ID
     */
    private function pageWith(string $handle, array $data): array
    {
        $page = $this->pages->create('Unknown fields ' . $handle, []);
        $page->addBlock(BlockType::getByHandle($handle), 'Main', $data);
        $cID = (int) $page->getCollectionID();

        return [$cID, $this->pages->blockIDs($cID)[0]];
    }

    private function versionCount(int $cID): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$cID]);
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
