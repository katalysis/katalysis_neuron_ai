<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\Traits\GetBlockToEditTrait;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Tools\Utilities\PageDraft;
use Katalysis\NeuronAi\Tools\Utilities\SystemPageGuard;

/**
 * P3.3-4: the block-write helpers ContentToolkit and BatchEditToolkit both use: the stored row and the draft copy of
 * a block (Concrete's GetBlockToEditTrait, aliased as it was in PagesToolkit). Moved from PagesToolkit unchanged; the
 * text check's views moved to BlockImportSave in P3.3-7.
 */
trait BlockWriteSupport
{
    use GetBlockToEditTrait {
        getBlockToWorkWith as protected coreGetBlockToWorkWith;
        getBlockToEdit as protected coreGetBlockToEdit;
    }

    /**
     * @param mixed $dbTable
     * @return array<string, mixed>
     */
    private function getBlockTableRecord($dbTable, int $blockID): array
    {
        if (!is_string($dbTable) || trim($dbTable) === '') {
            return [];
        }

        try {
            $conn = Application::getFacadeApplication()->make('database')->connection();
            $qb = $conn->createQueryBuilder();
            $qb->select('*')
                ->from($dbTable)
                ->where('bID = :bID')
                ->setParameter('bID', $blockID)
                ->setMaxResults(1);

            $row = $qb->execute()->fetchAssociative();
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Concrete's "block to edit", refusing a global area first (BF-2): for a global area it makes a stack version.
     */
    public function getBlockToEdit(Page $page, Area $area, string $areaHandle, $blockID): ?Block
    {
        SystemPageGuard::refuseGlobalArea($page, $areaHandle);

        $block = $this->coreGetBlockToEdit($page, $area, $areaHandle, $blockID);
        // P3.6-2: a later call in this request that names the old ID follows the copy (PageDraft::followCopies()).
        if ($block) {
            PageDraft::recordCopy((int) $page->getCollectionID(), (int) $blockID, (int) $block->getBlockID());
        }

        return $block;
    }
}
