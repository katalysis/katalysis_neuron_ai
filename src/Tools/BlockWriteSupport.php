<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\Traits\GetBlockToEditTrait;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Katalysis\NeuronAi\Tools\Utilities\BlockSaveInputs;
use Katalysis\NeuronAi\Tools\Utilities\SystemPageGuard;

/**
 * P3.3-4: the block-write helpers ContentToolkit and BatchEditToolkit both use: the stored row, the text check's views
 * and the draft copy of a block (Concrete's GetBlockToEditTrait, aliased as it was in PagesToolkit). Moved from
 * PagesToolkit unchanged.
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
     * The stored record and the update as the text check sees them (P3.3-6). An accordion's entries are a save()
     * input, not a column: the check compares them as text (titles and descriptions), not as JSON.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $value
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function textCheckViews(string $handle, int $bID, array $record, array $value): array
    {
        if ($handle === 'accordion' && is_string($value['accordionBlockData'] ?? null)) {
            if ($bID > 0) {
                $record['accordionBlockData'] = BlockSaveInputs::accordionText(BlockSaveInputs::accordionJson($bID));
            }
            $value['accordionBlockData'] = BlockSaveInputs::accordionText($value['accordionBlockData']);
        }

        return [$record, $value];
    }

    /**
     * Concrete's "block to edit", refusing a global area first (BF-2): for a global area it makes a stack version.
     */
    public function getBlockToEdit(Page $page, Area $area, string $areaHandle, $blockID): ?Block
    {
        SystemPageGuard::refuseGlobalArea($page, $areaHandle);

        return $this->coreGetBlockToEdit($page, $area, $areaHandle, $blockID);
    }
}
