<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * P3.3-7: the Level 0 generic block adapter. The block write tools learn a block type's fields from Concrete's own
 * export metadata (the block table, $btExportTables and the $btExport…Columns lists), save in import mode (the
 * columns as stored, as a CIF import does) into a draft, and write declared child tables (Image Slider's slides) as
 * rows. A field Level 0 can't describe is refused, never guessed.
 *
 * Validation (plans/P3.3-7.md, "Decisions taken" in the PR): each block type's own validate() runs on the
 * column-shaped data the import-mode save() gets; the generic checks (required columns, references that exist and that
 * the user may view, undeclared references unchanged, no script URLs) run for every type, and are the only checks for
 * types with no validate() of their own, such as Feature. Throwaway pages under /neuron-test. No AI provider call.
 */
final class GenericBlockAdapterTest extends ConcreteTestCase
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

    // ---- Image Slider: child rows ----------------------------------------------------------------------------

    public function testOneSlideCanBeChangedInADraftAndTheOtherSlideAndTheLiveBlockAreKept(): void
    {
        [$cID, $bID] = $this->pageWithSlider();
        [$first, $second] = $this->slideIDs($bID);

        $output = $this->update($cID, $bID, ['btImageSliderEntries' => [['id' => $first], ['id' => $second, 'title' => 'Renamed slide']]], 'scoped_text', ['btImageSliderEntries'], ['Second slide']);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $newID = (int) json_decode($output, true)['blockID'];
        $this->assertNotSame($bID, $newID, 'The slider was changed in place, not in a draft copy');
        $slides = $this->slides($newID);
        $this->assertSame(['First slide', 'Renamed slide'], array_column($slides, 'title'));
        $this->assertSame(['<p>One.</p>', '<p>Two.</p>'], array_column($slides, 'description'), 'The slides lost fields the call left out');
        $this->assertSame([$this->photoID(), $this->photoID()], array_map('intval', array_column($slides, 'fID')));
        $this->assertSame(['First slide', 'Second slide'], array_column($this->slides($bID), 'title'), 'The live block changed');
    }

    public function testASliderSettingChangeKeepsItsSlides(): void
    {
        [$cID, $bID] = $this->pageWithSlider();

        $output = ThrowawayPages::runTool(new BatchEditToolkit(), 'batch_update_page_content', [
            'pageID' => $cID, 'blocks' => [['areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['timeout' => 6000]]],
        ]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $newID = (int) json_decode($output, true)['blocks'][0]['newBlockID'];
        $this->assertSame(['First slide', 'Second slide'], array_column($this->slides($newID), 'title'), 'Image Slider\'s save() deletes its slides; they must be written back');
    }

    public function testASliderWithSlidesCanBeAdded(): void
    {
        [$cID] = $this->pageWithSlider();
        $photo = $this->photoID();

        $output = $this->add($cID, 'image_slider', ['timeout' => 4000, 'speed' => 500, 'btImageSliderEntries' => [
            ['fID' => $photo, 'title' => 'Slide A', 'description' => '<p>A.</p>'],
            ['fID' => $photo, 'title' => 'Slide B', 'description' => '<p>B.</p>'],
        ]]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $slides = $this->slides((int) json_decode($output, true)['blockID']);
        $this->assertSame(['Slide A', 'Slide B'], array_column($slides, 'title'));
        $this->assertSame(['0', '1'], array_map('strval', array_column($slides, 'sortOrder')));
    }

    /**
     * Decision: Image Slider's own validate() runs, on the columns the import-mode save() gets.
     */
    public function testImageSlidersOwnValidateRunsOnTheColumns(): void
    {
        [$cID] = $this->pageWithSlider();
        $versions = $this->versionCount($cID);
        $slides = ['btImageSliderEntries' => [['fID' => $this->photoID(), 'title' => 'Slide A']]];

        $noTimes = $this->add($cID, 'image_slider', $slides);
        $this->assertFalse(ToolKind::succeeded($noTimes), $noTimes);
        $this->assertStringContainsString('Slide Duration must be greater than 0', $noTimes);
        $this->assertStringContainsString('Nothing was changed', $noTimes);

        $tooClose = $this->add($cID, 'image_slider', ['timeout' => 500, 'speed' => 500] + $slides);
        $this->assertFalse(ToolKind::succeeded($tooClose), $tooClose);
        $this->assertStringContainsString('at least 100 ms greater', $tooClose);

        $this->assertSame($versions, $this->versionCount($cID), 'A refused add made a draft');
    }

    public function testAScriptUrlInASlideIsRefused(): void
    {
        [$cID, $bID] = $this->pageWithSlider();
        [$first] = $this->slideIDs($bID);

        $output = $this->update($cID, $bID, ['btImageSliderEntries' => [['id' => $first, 'linkURL' => 'javascript:alert(1)']]]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('javascript: URL', $output);
    }

    // ---- Feature: no validate() of its own, so the generic checks ---------------------------------------------

    /**
     * Decision: Feature has no validate() of its own (BlockController's passes everything), so the generic checks are
     * its validation: a page or file it refers to exists and the user may view it, and no script URL.
     */
    public function testFeatureWritesGoThroughTheGenericChecks(): void
    {
        [$cID, $bID] = $this->pageWith('feature', ['title' => 'Reading Room', 'paragraph' => '<p>Desks.</p>', 'link__which' => 'none']);
        $versions = $this->versionCount($cID);

        $missing = $this->add($cID, 'feature', ['title' => 'Linked', 'internalLinkCID' => 999999999]);
        $this->assertFalse(ToolKind::succeeded($missing), $missing);
        $this->assertStringContainsString('internalLinkCID', $missing);
        $this->assertStringContainsString('Nothing was changed', $missing);

        $script = $this->update($cID, $bID, ['externalLink' => 'javascript:alert(1)']);
        $this->assertFalse(ToolKind::succeeded($script), $script);
        $this->assertStringContainsString('javascript: URL', $script);
        $this->assertSame($versions, $this->versionCount($cID), 'A refused write made a draft');

        $parent = (int) Page::getByPath('/neuron-test')->getCollectionID();
        $linked = $this->update($cID, $bID, ['internalLinkCID' => $parent]);
        $this->assertTrue(ToolKind::succeeded($linked), $linked);
        $row = $this->row('btFeature', (int) json_decode($linked, true)['blockID']);
        $this->assertSame($parent, (int) $row['internalLinkCID']);
        $this->assertSame('Reading Room', $row['title']);
    }

    public function testFeatureLinkPickerInputsBecomeTheLinkColumns(): void
    {
        [$cID] = $this->pageWith('feature', ['title' => 'Reading Room', 'paragraph' => '<p>Desks.</p>', 'link__which' => 'none']);
        $parent = (int) Page::getByPath('/neuron-test')->getCollectionID();

        $output = $this->add($cID, 'feature', ['title' => 'Picked', 'link__which' => 'page', 'link_page' => $parent]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertSame($parent, (int) $this->row('btFeature', (int) json_decode($output, true)['blockID'])['internalLinkCID']);
    }

    public function testALinkToAPageTheUserMayNotViewIsRefused(): void
    {
        [$cID, $bID] = $this->pageWith('feature', ['title' => 'Reading Room', 'paragraph' => '<p>Desks.</p>', 'link__which' => 'none']);
        $hidden = Page::getByPath('/dashboard/system');
        ThrowawayPages::actAs('neuron_editor');
        $this->assertFalse((bool) (new Checker($hidden))->canViewPage(), 'Precondition: neuron_editor must not see /dashboard/system');

        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['internalLinkCID' => (int) $hidden->getCollectionID()],
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('internalLinkCID', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    // ---- Testimonial: file columns ---------------------------------------------------------------------------

    public function testATestimonialWithAPhotoCanBeAddedAndAMissingFileIsRefused(): void
    {
        [$cID] = $this->pageWith('testimonial', ['fID' => $this->photoID(), 'name' => 'Pat Reader', 'paragraph' => 'A friendly library.']);

        $added = $this->add($cID, 'testimonial', ['fID' => $this->photoID(), 'name' => 'Sam Reader', 'position' => 'Volunteer', 'paragraph' => 'I help on Saturdays.']);
        $this->assertTrue(ToolKind::succeeded($added), $added);
        $row = $this->row('btTestimonial', (int) json_decode($added, true)['blockID']);
        $this->assertSame($this->photoID(), (int) $row['fID']);
        $this->assertSame('Volunteer', $row['position']);

        $missing = $this->add($cID, 'testimonial', ['fID' => 999999999, 'name' => 'Nobody']);
        $this->assertFalse(ToolKind::succeeded($missing), $missing);
        $this->assertStringContainsString('fID', $missing);
    }

    /**
     * Glyn, after the first paid runs of 36 and 37: a file a block refers to needs view_file (the user may see it on the
     * site), not the File Manager's own permission, which the scenario editors don't have.
     */
    public function testAnEditorCanUseAFileTheyMaySeeOnTheSite(): void
    {
        // neuron_editor may edit the blocks of a throwaway page, but not add one (WriteChecksTest), so: give it a photo.
        [$cID, $bID] = $this->pageWith('testimonial', ['name' => 'Pat Reader', 'paragraph' => 'A friendly library.']);
        ThrowawayPages::actAs('neuron_editor');

        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['fID' => $this->photoID()],
        ]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertSame($this->photoID(), (int) $this->row('btTestimonial', (int) json_decode($output, true)['blockID'])['fID']);
    }

    // ---- What Level 0 can't describe is refused --------------------------------------------------------------

    public function testPageListFormInputsAreRefusedNamingTheColumnsToSet(): void
    {
        [$cID, $bID] = $this->pageWithPageList();

        $topic = $this->update($cID, $bID, ['topicFilter' => 'related']);
        $this->assertFalse(ToolKind::succeeded($topic), $topic);
        $this->assertStringContainsString('filterByRelated', $topic);

        $rss = $this->update($cID, $bID, ['rss' => 1, 'rssTitle' => 'News']);
        $this->assertFalse(ToolKind::succeeded($rss), $rss);
        $this->assertStringContainsString('RSS', $rss);
        $this->assertStringContainsString('Nothing was changed', $rss);
    }

    public function testAColumnThatRefersToSomethingLevel0CantNameKeepsItsValue(): void
    {
        [$cID, $bID] = $this->pageWithPageList();

        $output = $this->update($cID, $bID, ['customTopicTreeNodeID' => 7]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('customTopicTreeNodeID', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    public function testANewBlockNeedsItsRequiredColumns(): void
    {
        [$cID] = $this->pageWithPageList();

        $output = $this->add($cID, 'page_list', ['orderBy' => 'display_asc']);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString("'num'", $output);
    }

    // ---- Concrete's events, as the commands send them --------------------------------------------------------

    public function testAddAndEditSendConcretesBlockEvents(): void
    {
        [$cID, $bID] = $this->pageWith('feature', ['title' => 'Reading Room', 'paragraph' => '<p>Desks.</p>', 'link__which' => 'none']);
        $seen = [];
        $director = $this->app()->make('director');
        $listeners = [];
        foreach (['on_block_add', 'on_block_edit'] as $event) {
            $listeners[$event] = static function () use (&$seen, $event): void {
                $seen[] = $event;
            };
            $director->addListener($event, $listeners[$event]);
        }

        try {
            $this->assertTrue(ToolKind::succeeded($this->update($cID, $bID, ['title' => 'Reading Room and Cafe'])));
            $this->assertTrue(ToolKind::succeeded($this->add($cID, 'feature', ['title' => 'Study Space'])));
        } finally {
            foreach ($listeners as $event => $listener) {
                $director->removeListener($event, $listener);
            }
        }

        $this->assertSame(['on_block_edit', 'on_block_add'], $seen);
    }

    // ---- The read the model writes from ----------------------------------------------------------------------

    public function testGetBlockDataNamesEachFieldsKindAndResolvesReferences(): void
    {
        [$cID, $bID] = $this->pageWithSlider();
        $read = ThrowawayPages::runTool(new BlocksToolkit(), 'get_block_data', ['blockID' => $bID, 'pageID' => $cID]);

        $this->assertStringContainsString('btImageSliderEntries', $read);
        $this->assertStringContainsString('"id"', $read, 'Slides are listed with their id, which a write uses to keep a slide');
        $this->assertStringContainsString('Neuron Test Person Photo', $read, 'The slide\'s file is resolved to its name');

        [$fcID, $fbID] = $this->pageWith('feature', ['title' => 'Linked', 'paragraph' => '<p>Desks.</p>', 'link__which' => 'page', 'link_page' => (int) Page::getByPath('/neuron-test')->getCollectionID()]);
        $feature = ThrowawayPages::runTool(new BlocksToolkit(), 'get_block_data', ['blockID' => $fbID, 'pageID' => $fcID]);
        $this->assertMatchesRegularExpression('/internalLinkCID.*page.*\/neuron-test/', $feature);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    /**
     * @return array{0: int, 1: int}
     */
    private function pageWithSlider(): array
    {
        $photo = $this->photoID();

        return $this->pageWith('image_slider', [
            'timeout' => 4000, 'speed' => 500,
            'sortOrder' => [0, 1], 'fID' => [$photo, $photo], 'title' => ['First slide', 'Second slide'],
            'description' => ['<p>One.</p>', '<p>Two.</p>'], 'linkURL' => ['', ''], 'internalLinkCID' => [0, 0], 'linkType' => [0, 0],
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pageWithPageList(): array
    {
        $parent = (int) Page::getByPath('/neuron-test')->getCollectionID();

        return $this->pageWith('page_list', ['num' => 3, 'orderBy' => 'display_asc', 'cParentID' => $parent, 'pageListTitle' => 'Test pages']);
    }

    /**
     * @return array{0: int, 1: int} the page ID and the block's ID
     */
    private function pageWith(string $handle, array $data): array
    {
        $page = $this->pages->create('Level 0 ' . $handle, []);
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
     * @return list<int>
     */
    private function slideIDs(int $bID): array
    {
        return array_map('intval', array_column($this->slides($bID), 'id'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function slides(int $bID): array
    {
        return $this->db()->fetchAllAssociative('SELECT * FROM btImageSliderEntries WHERE bID = ? ORDER BY sortOrder', [$bID]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $bID): array
    {
        CacheLocal::flush();

        return $this->db()->fetchAssociative("SELECT * FROM {$table} WHERE bID = ?", [$bID]) ?: [];
    }

    private function versionCount(int $cID): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$cID]);
    }

    private function photoID(): int
    {
        $fID = (int) $this->db()->fetchOne("SELECT MIN(fID) FROM FileVersions WHERE fvTitle = 'Neuron Test Person Photo' AND fvIsApproved = 1");
        $this->assertGreaterThan(0, $fID, 'Run the seed script first.');

        return $fID;
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
