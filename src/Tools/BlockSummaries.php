<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\Block;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Tools\Utilities\HtmlToText;

/**
 * P3.3-4: how a block is described in a result (its preview, fields and sub-areas), shared by PagesToolkit and
 * ContentToolkit. Moved from PagesToolkit unchanged.
 */
trait BlockSummaries
{
    protected function serializeBlock(?Block $block, bool $includeDetails = false): array
    {
        if (!$block) {
            return [];
        }

        $data = [
            'blockID' => $block->getBlockID(),
            'blockType' => $block->getBlockTypeHandle(),
            'blockTypeName' => $block->getBlockTypeName(),
            'blockName' => $block->getBlockName(),
            'areaHandle' => $block->getAreaHandle(),
            'displayOrder' => $block->getBlockDisplayOrder(),
        ];

        $preview = $this->extractBlockPreview($block);
        if ($preview !== null && $preview !== '') {
            $data += $this->blockPreviewFields($preview);
        }

        if ($includeDetails) {
            $controller = $block->getController();
            $databaseTable = $controller ? $controller->getBlockTypeDatabaseTable() : null;
            $data['details'] = [
                'collectionID' => $block->getBlockCollectionID(),
                'databaseTable' => $databaseTable ?: null,
            ];
        }

        return $data;
    }

    /**
     * A block's text preview, marked when it is shortened, with the tool that returns the full text (BF-5, B-TRU-7).
     *
     * @return array{contentPreview: string, contentPreviewShortened?: array{fullLength: int, fullTextTool: string}}
     */
    private function blockPreviewFields(string $text): array
    {
        $info = HtmlToText::previewInfo($text, 200);
        $fields = ['contentPreview' => $info['preview']];
        if ($info['shortened']) {
            $fields['contentPreviewShortened'] = ['fullLength' => $info['fullLength'], 'fullTextTool' => 'get_block_data'];
        }

        return $fields;
    }

    /**
     * How many blocks the page version has in container and layout columns. The area lists that use
     * Area::getHandleList() (top-level areas only) leave them out, so the result says so (BF-5 R11).
     *
     * @return array{subAreaBlocks: int, subAreaNote?: string}
     */
    protected function subAreaSummary(Page $page): array
    {
        $count = (int) Application::getFacadeApplication()->make('database')->connection()->fetchOne(
            'SELECT COUNT(*) FROM CollectionVersionBlocks cvb
             JOIN Areas a ON a.cID = cvb.cID AND a.arHandle = cvb.arHandle
             WHERE cvb.cID = ? AND cvb.cvID = ? AND a.arParentID > 0',
            [(int) $page->getCollectionID(), (int) $page->getVersionID()]
        );

        $summary = ['subAreaBlocks' => $count];
        if ($count > 0) {
            $summary['subAreaNote'] = "{$count} block(s) in container or layout columns are not listed here; get_page_content lists them.";
        }

        return $summary;
    }

    private function extractBlockPreview(Block $block): ?string
    {
        try {
            return $this->extractBlockText($block);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The text of a block, or null. Unlike extractBlockPreview() this lets a failing
     * block controller throw, so get_page_content can report it.
     */
    protected function extractBlockText(Block $block): ?string
    {
        $controller = $block->getController();
        if (!$controller) {
            return null;
        }

        if (method_exists($controller, 'getSearchableContent')) {
            $content = (string) $controller->getSearchableContent();
            if (trim($content) !== '') {
                return $content;
            }
        }

        if (method_exists($controller, 'getContent')) {
            $content = (string) $controller->getContent();
            if (trim($content) !== '') {
                return $content;
            }
        }

        if (method_exists($controller, 'getContentEditMode')) {
            $content = (string) $controller->getContentEditMode();
            if (trim($content) !== '') {
                return $content;
            }
        }

        return null;
    }
}
