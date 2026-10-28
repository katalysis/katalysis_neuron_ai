<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.3-6: the block write tools on the core blocks Katalysis uses most (Image, Page List, Feature, Accordion and the
 * Express form; Content is covered elsewhere). For each: an edit of one field keeps the others, and a new block can be
 * added with the fields a model would send. Built like the seed's /neuron-test/block-types page, on a throwaway page.
 *
 * The edits go through the block's own save(), which for some types reads form inputs that are not table columns
 * (Concrete's destination picker "link__which", the accordion's JSON entries). No AI provider call.
 */
final class CoreBlockWritesTest extends ConcreteTestCase
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

    public function testImageEditKeepsTheFileAndAddWorks(): void
    {
        $fID = $this->photoID();
        [$cID, $bID] = $this->pageWith('image', ['fID' => $fID, 'altText' => 'Old alt', 'title' => '', 'imageLink__which' => 'none']);

        // Replacing text names what it replaces (scoped_text, BF-5).
        $output = $this->update($cID, $bID, ['altText' => 'New alt text'], 'scoped_text', ['altText'], ['Old alt']);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        $row = $this->newestRow($cID, 'image', 'btContentImage');
        $this->assertSame('New alt text', $row['altText']);
        $this->assertSame($fID, (int) $row['fID']);

        $added = $this->add($cID, 'image', ['fID' => $fID, 'altText' => 'Added image']);
        $this->assertTrue(ToolKind::succeeded($added), $added);
    }

    public function testPageListSettingEditKeepsTheParentAndAddWorks(): void
    {
        $parent = (int) Page::getByPath('/neuron-test')->getCollectionID();
        [$cID, $bID] = $this->pageWith('page_list', ['num' => 3, 'orderBy' => 'display_asc', 'cParentID' => $parent, 'pageListTitle' => 'Test pages', 'includeName' => 1]);

        // As a model sends it: a string. A number is a setting, not text, under keep_text.
        $output = $this->update($cID, $bID, ['num' => '5']);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        $row = $this->newestRow($cID, 'page_list', 'btPageList');
        $this->assertSame('5', (string) $row['num']);
        $this->assertSame((string) $parent, (string) $row['cParentID']);
        $this->assertSame('Test pages', $row['pageListTitle']);

        $added = $this->add($cID, 'page_list', ['num' => 4, 'cParentID' => $parent, 'orderBy' => 'display_asc']);
        $this->assertTrue(ToolKind::succeeded($added), $added);
    }

    public function testFeatureEditKeepsTheParagraphAndAddWorksWithoutALink(): void
    {
        [$cID, $bID] = $this->pageWith('feature', ['icon' => 'fas fa-star', 'title' => 'Old title', 'paragraph' => '<p>Paragraph.</p>', 'titleFormat' => 'h4', 'link__which' => 'none']);

        $output = $this->update($cID, $bID, ['title' => 'New title'], 'scoped_text', ['title'], ['Old title']);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        $row = $this->newestRow($cID, 'feature', 'btFeature');
        $this->assertSame('New title', $row['title']);
        $this->assertStringContainsString('Paragraph.', (string) $row['paragraph']);

        $added = $this->add($cID, 'feature', ['title' => 'Added feature', 'paragraph' => '<p>Added text.</p>']);
        $this->assertTrue(ToolKind::succeeded($added), $added);
    }

    public function testAccordionSettingEditKeepsItsEntriesAndAnEntryCanBeChanged(): void
    {
        $entries = [['title' => 'First question', 'description' => '<p>First answer.</p>'], ['title' => 'Second question', 'description' => '<p>Second answer.</p>']];
        [$cID, $bID] = $this->pageWith('accordion', ['initialState' => 'closed', 'itemHeadingFormat' => 'h4', 'accordionBlockData' => json_encode($entries)]);

        // A setting only: the entries must survive (the block's save() deletes them and re-adds what it is given).
        $output = $this->update($cID, $bID, ['itemHeadingFormat' => 'h3']);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        $newID = (int) json_decode($output, true)['blockID'];
        $this->assertSame(['First question', 'Second question'], $this->accordionTitles($newID), 'A settings edit lost the accordion entries');

        // The data a read gives the model shows the entries.
        $read = ThrowawayPages::runTool(new BlocksToolkit(), 'get_block_data', ['blockID' => $newID, 'pageID' => $cID]);
        $this->assertStringContainsString('Second question', $read, 'get_block_data does not show the accordion entries');

        // One entry's title.
        $changed = $entries;
        $changed[1]['title'] = 'Can I renew by phone?';
        $output = $this->update($cID, $newID, ['accordionBlockData' => json_encode($changed)], 'scoped_text', ['accordionBlockData'], ['Second question']);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertSame(['First question', 'Can I renew by phone?'], $this->accordionTitles((int) json_decode($output, true)['blockID']));

        // The batch tool keeps them too.
        $batchBlock = (int) json_decode($output, true)['blockID'];
        $batch = ThrowawayPages::runTool(new BatchEditToolkit(), 'batch_update_page_content', [
            'pageID' => $cID, 'blocks' => [['areaHandle' => 'Main', 'blockID' => $batchBlock, 'value' => ['initialState' => 'open']]],
        ]);
        $this->assertTrue(ToolKind::succeeded($batch), $batch);
        $this->assertSame(['First question', 'Can I renew by phone?'], $this->accordionTitles((int) json_decode($batch, true)['blocks'][0]['newBlockID']));

        $added = $this->add($cID, 'accordion', ['accordionBlockData' => json_encode([['title' => 'New question', 'description' => '<p>New answer.</p>']])]);
        $this->assertTrue(ToolKind::succeeded($added), $added);
    }

    public function testExpressFormSettingEditKeepsTheFormAndFieldChangesAreRefused(): void
    {
        $formID = $this->roomFormID();
        [$cID, $bID] = $this->pageWith('express_form', ['exFormID' => $formID, 'submitLabel' => 'Send', 'thankyouMsg' => 'Thanks.', 'storeFormSubmission' => 1]);

        $output = $this->update($cID, $bID, ['submitLabel' => 'Send enquiry']);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        $row = $this->newestRow($cID, 'express_form', 'btExpressForm');
        $this->assertSame('Send enquiry', $row['submitLabel']);
        $this->assertSame($formID, $row['exFormID']);

        $fields = $this->update($cID, (int) json_decode($output, true)['blockID'], ['fields' => [['label' => 'Phone', 'type' => 'text']]]);
        $this->assertFalse(ToolKind::succeeded($fields), $fields);
        $this->assertStringContainsString("form's fields", $fields);
        $this->assertStringContainsString('Nothing was changed', $fields);

        $added = $this->add($cID, 'express_form', ['exFormID' => $formID, 'submitLabel' => 'Book']);
        $this->assertTrue(ToolKind::succeeded($added), $added);
    }

    // ---- found by scenarios 29, 31 and 32 (P3.3-6's paid runs) ----------------------------------------------

    public function testAPageListSettingChangeInScopedTextWithNoTextFieldsIsSaved(): void
    {
        $parent = (int) Page::getByPath('/neuron-test')->getCollectionID();
        [$cID, $bID] = $this->pageWith('page_list', ['num' => 3, 'orderBy' => 'display_asc', 'cParentID' => $parent, 'pageListTitle' => 'Test pages']);

        // Scenario 29: the model sent this, was refused ("scoped_text mode requires textFields"), and said it was done.
        $output = $this->update($cID, $bID, ['num' => 5], 'scoped_text', [], []);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertSame('5', (string) $this->newestRow($cID, 'page_list', 'btPageList')['num']);
    }

    public function testAccordionEntriesSentAsAnArrayAreSaved(): void
    {
        $entries = [['title' => 'How do I join?', 'description' => '<p>Ask at the front desk.</p>'], ['title' => 'Can I renew online?', 'description' => '<p>Yes, from your account page.</p>']];
        [$cID, $bID] = $this->pageWith('accordion', ['initialState' => 'closed', 'itemHeadingFormat' => 'h4', 'accordionBlockData' => json_encode($entries)]);

        // Scenario 31: an array, not a JSON string. The update was refused (its replacedText "not found") and the add threw.
        $changed = $entries;
        $changed[1]['title'] = 'Can I renew by phone?';
        $output = $this->update($cID, $bID, ['accordionBlockData' => $changed], 'scoped_text', ['accordionBlockData'], ['Can I renew online?']);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertSame(['How do I join?', 'Can I renew by phone?'], $this->accordionTitles((int) json_decode($output, true)['blockID']));

        $added = $this->add($cID, 'accordion', ['accordionBlockData' => [['title' => 'Where do I park?', 'description' => '<p>Behind the library.</p>']]]);
        $this->assertTrue(ToolKind::succeeded($added), $added);
        $this->assertSame(['Where do I park?'], $this->accordionTitles((int) json_decode($added, true)['blockID']));
    }

    public function testAskingForFormBlocksNamesTheExpressFormBlockThePageHas(): void
    {
        [$cID] = $this->pageWith('express_form', ['exFormID' => $this->roomFormID(), 'submitLabel' => 'Send']);

        // Scenario 32: "No 'form' blocks found", and the model told the user there was no form on the page.
        $output = ThrowawayPages::runTool(new BlocksToolkit(), 'list_page_blocks_by_type', ['pageID' => $cID, 'blockTypeHandle' => 'form']);

        $this->assertStringContainsString('`express_form`', $output);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    /**
     * @return array{0: int, 1: int} the page ID and the block's ID
     */
    private function pageWith(string $handle, array $data): array
    {
        $page = $this->pages->create('Core block ' . $handle, []);
        $page->addBlock(BlockType::getByHandle($handle), 'Main', $data);
        $cID = (int) $page->getCollectionID();

        return [$cID, $this->pages->blockIDs($cID)[0]];
    }

    private function update(int $cID, int $bID, array $value, ?string $mode = null, ?array $textFields = null, ?array $replaced = null): string
    {
        $inputs = ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID, 'value' => $value];
        if ($mode !== null) {
            $inputs += ['changeMode' => $mode, 'textFields' => $textFields, 'replacedText' => $replaced];
        }
        ThrowawayPages::actAs('superuser');

        return ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', $inputs);
    }

    private function add(int $cID, string $handle, array $value): string
    {
        ThrowawayPages::actAs('superuser');

        return ThrowawayPages::runTool(new ContentToolkit(), 'add_block_to_page_area', ['pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => $handle, 'value' => $value]);
    }

    /**
     * @return array<string, mixed> the first block of this type in the newest version
     */
    private function newestRow(int $cID, string $handle, string $table): array
    {
        CacheLocal::flush();
        $cvID = (int) Page::getByID($cID, 'RECENT')->getVersionID();
        $bID = (int) $this->db()->fetchOne(
            'SELECT cvb.bID FROM CollectionVersionBlocks cvb JOIN Blocks b ON b.bID = cvb.bID JOIN BlockTypes bt ON bt.btID = b.btID'
            . ' WHERE cvb.cID = ? AND cvb.cvID = ? AND bt.btHandle = ? ORDER BY cvb.cbDisplayOrder LIMIT 1',
            [$cID, $cvID, $handle]
        );

        return $this->db()->fetchAssociative("SELECT * FROM {$table} WHERE bID = ?", [$bID]) ?: [];
    }

    /**
     * @return list<string>
     */
    private function accordionTitles(int $bID): array
    {
        return array_map('strval', $this->db()->fetchFirstColumn('SELECT title FROM btAccordionEntries WHERE bID = ? ORDER BY sortOrder', [$bID]));
    }

    private function photoID(): int
    {
        $fID = (int) $this->db()->fetchOne("SELECT MIN(fID) FROM FileVersions WHERE fvTitle = 'Neuron Test Person Photo' AND fvIsApproved = 1");
        $this->assertGreaterThan(0, $fID, 'Run the seed script first.');

        return $fID;
    }

    private function roomFormID(): string
    {
        $id = (string) $this->db()->fetchOne(
            'SELECT f.id FROM ExpressForms f JOIN ExpressEntities e ON e.id = f.entity_id WHERE e.handle = ? LIMIT 1',
            ['neuron_test_room']
        );
        $this->assertNotSame('', $id, 'The seeded Express object has no form. Run the seed reset.');

        return $id;
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
