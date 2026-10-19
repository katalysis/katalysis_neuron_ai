<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Area\Area;
use Concrete\Core\Page\Page;

/**
 * Block ID remapping utility for safe page version updates
 * 
 * When a new page version is created, Concrete CMS assigns new block IDs.
 * This utility maps old block IDs to new ones by matching area handle + position.
 * 
 * Based on Macareux MCP server implementation
 * @see https://github.com/MacareuxDigital/concretecms-mcp-server/blob/main/src/tools/remapBlockIds.ts
 */
class BlockIdRemapper
{
    /**
     * Remap block IDs from a before-version layout to after-version layout
     * 
     * @param array $beforeAreas Area snapshots before version creation [['handle' => 'Main', 'blocks' => [['id' => 123, 'type' => 'content'], ...]]]
     * @param array $afterAreas Area snapshots after version creation
     * @param array $updates Block updates to remap [['areaHandle' => 'Main', 'blockID' => 123, 'value' => [...]]]
     * @return array Remapped updates with new block IDs [['areaHandle' => 'Main', 'oldBlockID' => 123, 'newBlockID' => 456, 'value' => [...]]]
     * @throws BlockRemapException
     */
    public static function remap(array $beforeAreas, array $afterAreas, array $updates): array
    {
        $idMap = self::buildIdMap($beforeAreas, $afterAreas);
        $remapped = [];

        foreach ($updates as $update) {
            $areaHandle = $update['areaHandle'] ?? null;
            $blockID = $update['blockID'] ?? null;
            $value = $update['value'] ?? [];

            if (!$areaHandle || !$blockID) {
                throw new BlockRemapException('Each update must have areaHandle and blockID');
            }

            // Look up the new block ID
            $newBlockID = $idMap[$blockID] ?? null;
            if ($newBlockID === null) {
                throw new BlockRemapException(
                    "Cannot remap block {$blockID} in area \"{$areaHandle}\": block not found in the pre-update page layout"
                );
            }

            // Verify the area exists in the after layout
            $afterArea = null;
            foreach ($afterAreas as $area) {
                if ($area['handle'] === $areaHandle) {
                    $afterArea = $area;
                    break;
                }
            }

            if (!$afterArea) {
                throw new BlockRemapException(
                    "Area \"{$areaHandle}\" not found on the page after creating a new version"
                );
            }

            // Verify the new block ID is actually in the target area
            $foundInArea = false;
            foreach ($afterArea['blocks'] ?? [] as $block) {
                if ($block['id'] === $newBlockID) {
                    $foundInArea = true;
                    break;
                }
            }

            if (!$foundInArea) {
                throw new BlockRemapException(
                    "Remapped block {$blockID} → {$newBlockID} is not in area \"{$areaHandle}\" after creating a new version"
                );
            }

            $remapped[] = [
                'areaHandle' => $areaHandle,
                'oldBlockID' => $blockID,
                'newBlockID' => $newBlockID,
                'value' => $value,
            ];
        }

        return $remapped;
    }

    /**
     * Build a map of old block ID → new block ID
     * 
     * Matches blocks by area handle and position within the area
     * 
     * @param array $beforeAreas
     * @param array $afterAreas
     * @return array Map of oldBlockID => newBlockID
     */
    private static function buildIdMap(array $beforeAreas, array $afterAreas): array
    {
        $afterByHandle = [];
        foreach ($afterAreas as $area) {
            $afterByHandle[$area['handle']] = $area;
        }

        $idMap = [];

        foreach ($beforeAreas as $beforeArea) {
            $handle = $beforeArea['handle'];
            $afterArea = $afterByHandle[$handle] ?? null;

            if (!$afterArea) {
                continue;
            }

            $beforeBlocks = $beforeArea['blocks'] ?? [];
            $afterBlocks = $afterArea['blocks'] ?? [];
            $count = min(count($beforeBlocks), count($afterBlocks));

            for ($i = 0; $i < $count; $i++) {
                $oldId = $beforeBlocks[$i]['id'];
                $newId = $afterBlocks[$i]['id'];
                $idMap[$oldId] = $newId;
            }
        }

        return $idMap;
    }

    /**
     * Capture a snapshot of page areas and blocks (before version creation)
     * 
     * @param Page $page
     * @return array Area snapshot [['handle' => 'Main', 'blocks' => [['id' => 123, 'type' => 'content'], ...]]]
     */
    public static function captureAreaSnapshot(Page $page): array
    {
        $areas = [];
        $areaHandles = Area::getHandleList();

        foreach ($areaHandles as $handle) {
            $blocks = $page->getBlocks($handle);
            
            if (empty($blocks)) {
                continue;
            }

            $blockData = [];
            foreach ($blocks as $block) {
                $blockData[] = [
                    'id' => (int) $block->getBlockID(),
                    'type' => $block->getBlockTypeHandle(),
                ];
            }

            $areas[] = [
                'handle' => $handle,
                'blocks' => $blockData,
            ];
        }

        return $areas;
    }
}

/**
 * Exception thrown when block ID remapping fails
 */
class BlockRemapException extends \RuntimeException
{
}
