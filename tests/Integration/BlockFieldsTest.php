<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\Utilities\BlockFields;

/**
 * P3.3-7: what the Level 0 adapter learns about a block type from Concrete's own metadata: its tables, each column's
 * kind (from the $btExport…Columns lists, else its database type), the child tables it declares, and what it can't
 * describe. Read from the dev site's installed core block types (Concrete 9.5.5). Reads only.
 */
final class BlockFieldsTest extends ConcreteTestCase
{
    public function testFeatureColumnsTakeTheirKindsFromTheExportLists(): void
    {
        $fields = $this->fields('feature');

        $this->assertNull($fields->refusal());
        $this->assertSame('btFeature', $fields->table());
        $kinds = array_map(static fn (array $c): string => $c['kind'], $fields->columns());
        $this->assertSame('page', $kinds['internalLinkCID']);
        $this->assertSame('file', $kinds['fID']);
        $this->assertSame('html', $kinds['paragraph']);
        $this->assertSame('text', $kinds['title']);
        $this->assertArrayNotHasKey('bID', $kinds);
        $this->assertSame([], $fields->childTables());
    }

    public function testImageSliderDeclaresItsSlidesAsAWritableChildTable(): void
    {
        $fields = $this->fields('image_slider');

        $this->assertSame(['btImageSliderEntries'], $fields->childTables());
        $this->assertNull($fields->childTableRefusal('btImageSliderEntries'));
        $kinds = array_map(static fn (array $c): string => $c['kind'], $fields->columns('btImageSliderEntries'));
        $this->assertSame('file', $kinds['fID']);
        $this->assertSame('page', $kinds['internalLinkCID']);
        $this->assertSame('html', $kinds['description']);
        $this->assertArrayNotHasKey('id', $kinds);
        $this->assertArrayNotHasKey('bID', $kinds);
        $this->assertSame(['cID'], $fields->undeclaredReferences('btImageSliderEntries'));
    }

    public function testAccordionAndFaqEntriesAreWritableChildTables(): void
    {
        $this->assertSame(['btAccordionEntries'], $this->fields('accordion')->childTables());
        $this->assertNull($this->fields('accordion')->childTableRefusal('btAccordionEntries'));
        $this->assertNull($this->fields('faq')->childTableRefusal('btFaqEntries'));
    }

    public function testChildTablesWhoseImportIsTheBlocksOwnCodeAreNotWritten(): void
    {
        foreach (['gallery' => 'btGalleryEntries', 'survey' => 'btSurveyOptions', 'form' => 'btFormQuestions'] as $handle => $table) {
            $this->assertNotNull($this->fields($handle)->childTableRefusal($table), "{$handle}'s {$table} should not be written by Level 0");
        }
    }

    public function testImageThumbnailTypesAreASideTableTheExportDoesNotDescribe(): void
    {
        $fields = $this->fields('image');

        $this->assertSame([], $fields->childTables());
        $this->assertContains('btContentImageBreakpoints', $fields->sideTables());
    }

    public function testPageListKindsRequiredColumnsAndUndeclaredReferences(): void
    {
        $fields = $this->fields('page_list');
        $kinds = array_map(static fn (array $c): string => $c['kind'], $fields->columns());

        $this->assertSame('page', $kinds['cParentID']);
        $this->assertSame('page_type', $kinds['ptID']);
        $this->assertSame('page_feed', $kinds['pfID']);
        $this->assertSame('number', $kinds['num']);
        $this->assertSame(['customTopicTreeNodeID'], $fields->undeclaredReferences());
        $this->assertContains('num', $fields->required());
    }

    public function testExpressFormsFormIsAnUndeclaredReference(): void
    {
        $fields = $this->fields('express_form');
        $kinds = array_map(static fn (array $c): string => $c['kind'], $fields->columns());

        $this->assertContains('exFormID', $fields->undeclaredReferences());
        $this->assertSame('file_folder', $kinds['addFilesToFolder']);
        $this->assertSame('page', $kinds['redirectCID']);
    }

    public function testSystemBlocksAreNotDescribed(): void
    {
        $this->assertNotNull($this->fields('core_area_layout')->refusal());
    }

    private function fields(string $handle): BlockFields
    {
        $type = BlockType::getByHandle($handle);
        $this->assertNotNull($type, "Block type {$handle} is not installed.");

        return BlockFields::ofType($type);
    }
}
