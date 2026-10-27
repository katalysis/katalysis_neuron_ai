<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.3-2: the block write tools' checks, each before any draft, so a refused call changes nothing.
 *
 * - no code in content (B-T1-10): a <script> element, an event-handler attribute or a javascript: URL is refused
 *   by every tool that writes block text, unless the stored value already has it
 * - add_block_to_page_area runs the block type's own validate(), as Concrete's add-block dialog does
 * - a refused add or move leaves no Areas row (Area::getOrCreate() ran before the checks; BF-3 plan §9)
 * - update_block_in_page_area saves once, and its result names the block ID it replaced
 * - a refusal says what is wrong and what to do next, and that nothing was changed (B-ERR-4)
 *
 * Throwaway pages under /neuron-test, deleted again. No AI provider call.
 */
final class WriteChecksTest extends ConcreteTestCase
{
    private const SCRIPT = '<p>Block one original text.</p><script>alert(1)</script>';

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

    // ---- B-T1-10: no code in content ------------------------------------------------------------------------

    public function testAddBlockRefusesCode(): void
    {
        $cID = (int) $this->pages->create('No code, add')->getCollectionID();
        $before = $this->pages->state($cID);

        foreach (['content' => self::SCRIPT, 'html' => '<img src="/a.png" alt="" onerror="alert(1)">'] as $type => $html) {
            $output = $this->tool('add_block_to_page_area', ['pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => $type, 'value' => ['content' => $html]]);
            $this->assertFalse(ToolKind::succeeded($output), "{$type}: {$output}");
            $this->assertStringContainsString('Nothing was changed', $output);
        }
        $this->assertSame($before, $this->pages->state($cID), 'A refused add changed the page');
    }

    public function testUpdateBlockRefusesCode(): void
    {
        $cID = (int) $this->pages->create('No code, update')->getCollectionID();
        $bID = $this->pages->blockIDs($cID)[0];
        $before = $this->pages->state($cID);

        $output = $this->tool('update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => '<p>Block one original text. <a href="javascript:alert(1)">More</a></p>'],
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('javascript: URL', $output);
        $this->assertSame($before, $this->pages->state($cID), 'A refused update changed the page');
    }

    public function testBatchUpdateRefusesCode(): void
    {
        $cID = (int) $this->pages->create('No code, batch')->getCollectionID();
        $bID = $this->pages->blockIDs($cID)[0];
        $before = $this->pages->state($cID);

        $output = $this->tool('batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [['areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['content' => self::SCRIPT]]],
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('<script>', $output);
        $this->assertSame($before, $this->pages->state($cID), 'A refused batch changed the page');
    }

    public function testAnHtmlBlockKeepsTheScriptItAlreadyHas(): void
    {
        $page = $this->pages->create('Embed kept', []);
        $cID = (int) $page->getCollectionID();
        $embed = '<div id="map"></div><script src="https://maps.example.test/embed.js"></script>';
        $page->addBlock(BlockType::getByHandle('html'), 'Main', ['content' => $embed]);
        $bID = $this->pages->blockIDs($cID)[0];

        $output = $this->tool('update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => '<p>Our map:</p>' . $embed],
        ]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
    }

    // ---- add_block_to_page_area: the block's own validate() -------------------------------------------------

    public function testAddBlockRunsTheBlockTypesValidateAndLeavesNoAreaRow(): void
    {
        $cID = (int) $this->pages->create('Validate on add')->getCollectionID();
        $before = $this->pages->state($cID);
        $areas = $this->areaCount($cID);

        $output = $this->tool('add_block_to_page_area', [
            'pageID' => $cID, 'areaHandle' => 'P3.3-2 New Area', 'blockType' => 'file',
            'value' => ['fID' => 0, 'fileLinkText' => ''],
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('You must select a file', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($before, $this->pages->state($cID), 'A refused add made a draft');
        $this->assertSame($areas, $this->areaCount($cID), 'A refused add left an Areas row');
    }

    public function testARefusedMoveLeavesNoAreaRow(): void
    {
        $cID = (int) $this->pages->create('Refused move')->getCollectionID();
        $bID = $this->pages->blockIDs($cID)[0];
        $areas = $this->areaCount($cID);

        // neuron_editor may edit the page's blocks but has no add-block grant on a new area.
        ThrowawayPages::actAs('neuron_editor');
        $output = ThrowawayPages::runTool(new PagesToolkit(), 'move_block_between_areas', [
            'pageID' => $cID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'P3.3-2 Destination', 'blockID' => $bID,
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('Permission denied', $output);
        $this->assertSame($areas, $this->areaCount($cID), 'A refused move left an Areas row');
    }

    // ---- update_block_in_page_area: one save, and the ID it replaced ----------------------------------------

    public function testUpdateBlockSavesOnceAndNamesThePreviousBlockID(): void
    {
        $cID = (int) $this->pages->create('One save')->getCollectionID();
        $bID = $this->pages->blockIDs($cID)[0];

        // Block::update() fires no event, so the saves are counted as writes to the content block's table.
        $configuration = $this->db()->getConfiguration();
        $previousLogger = $configuration->getSQLLogger();
        $log = new \Doctrine\DBAL\Logging\DebugStack();
        $configuration->setSQLLogger($log);
        try {
            $output = $this->tool('update_block_in_page_area', [
                'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
                'value' => ['content' => '<p>Block one original text.</p><p>Added.</p>'],
            ]);
        } finally {
            $configuration->setSQLLogger($previousLogger);
        }

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $result = json_decode($output, true);
        $this->assertSame($bID, $result['previousBlockID'] ?? null, $output);
        $this->assertNotSame($bID, $result['blockID']);
        $newID = (int) $result['blockID'];
        $saves = array_filter($log->queries, static fn (array $q): bool => preg_match('/^\s*(UPDATE|REPLACE|INSERT)\b.*btContentLocal/is', (string) $q['sql']) === 1
            && in_array($newID, array_map('intval', array_values((array) ($q['params'] ?? []))), true));
        $this->assertCount(2, $saves, 'Expected the copy into the draft and one save; ' . count($saves) . ' writes: ' . json_encode(array_column($saves, 'sql')));
    }

    // ---- refusals say what is wrong and what to do next (B-ERR-4) --------------------------------------------

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function missingPageProvider(): array
    {
        $gone = 999999999;

        return [
            'batch_update_page_content' => ['batch_update_page_content', ['pageID' => $gone, 'blocks' => [['areaHandle' => 'Main', 'blockID' => 1, 'value' => ['content' => '<p>x</p>']]]]],
            'batch_update_content_headings' => ['batch_update_content_headings', ['pageID' => $gone, 'headingTexts' => ['A']]],
            'update_page' => ['update_page', ['pageID' => $gone, 'name' => 'X']],
            'add_block_to_page_area' => ['add_block_to_page_area', ['pageID' => $gone, 'areaHandle' => 'Main', 'blockType' => 'content', 'value' => ['content' => '<p>x</p>']]],
            'update_block_in_page_area' => ['update_block_in_page_area', ['pageID' => $gone, 'areaHandle' => 'Main', 'blockID' => 1, 'value' => ['content' => '<p>x</p>']]],
            'delete_block_from_page_area' => ['delete_block_from_page_area', ['pageID' => $gone, 'areaHandle' => 'Main', 'blockID' => 1]],
            'move_block_between_areas' => ['move_block_between_areas', ['pageID' => $gone, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => 1]],
            'create_page' => ['create_page', ['parentID' => $gone, 'name' => 'X']],
        ];
    }

    /**
     * @param array<string, mixed> $inputs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingPageProvider')]
    public function testAMissingPageIsReportedWithTheNextStep(string $tool, array $inputs): void
    {
        $output = $this->tool($tool, $inputs);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('not found', $output);
        $this->assertStringContainsString('get_current_page_context or list_pages', $output, 'The refusal says how to find the page');
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    public function testAnUnknownBlockTypeSaysNotToChooseAnother(): void
    {
        $cID = (int) $this->pages->create('Unknown type')->getCollectionID();

        $output = $this->tool('add_block_to_page_area', ['pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'no_such_block', 'value' => []]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString("No block type 'no_such_block' is installed", $output);
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    private function tool(string $name, array $inputs): string
    {
        ThrowawayPages::actAs('superuser');

        return ThrowawayPages::runTool(new PagesToolkit(), $name, $inputs);
    }

    private function areaCount(int $cID): int
    {
        CacheLocal::flush();

        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM Areas WHERE cID = ?', [$cID]);
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
