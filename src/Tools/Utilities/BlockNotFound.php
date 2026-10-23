<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;

/**
 * Why a block write tool can't find the block it was given, in words the model can act on (BF-7, Glyn's browser
 * check 6). The write tools edit the page's newest version. A block's first change in a draft copies it under a new
 * ID, whether the change came from edit mode or the assistant, so an ID read from the published version (or before
 * a hand edit) is no longer in the newest version. "Block X not found" made the model guess at locks and permissions;
 * this says where the block is, and which ID the newest version has in its place.
 */
final class BlockNotFound
{
    /**
     * Throws the explanation unless the block is in the area of the page's newest version. For tools that check before
     * any lookup of their own (batch_update_page_content).
     *
     * @throws \RuntimeException
     */
    public static function refuseUnlessInNewestVersion(Page $page, string $areaHandle, int $blockID): void
    {
        $recent = self::recentVersionID($page);
        $found = self::db()->fetchOne(
            'SELECT 1 FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? AND bID = ? AND arHandle = ?',
            [(int) $page->getCollectionID(), $recent, $blockID, $areaHandle]
        );
        if ($found === false) {
            throw new \RuntimeException(self::explain($page, $areaHandle, $blockID));
        }
    }

    public static function explain(Page $page, string $areaHandle, int $blockID): string
    {
        $cID = (int) $page->getCollectionID();
        $recent = self::recentVersionID($page);
        $placements = self::placements($cID, $blockID);

        if ($placements === []) {
            return "Block {$blockID} not found: it is not on page {$cID}, in any version of it. Nothing was changed. Read the page's blocks "
                . 'with get_page_blocks (it reads the newest version) and use an ID from there.';
        }

        foreach ($placements as $placement) {
            if ((int) $placement['cvID'] === $recent) {
                return "Block {$blockID} is in area '{$placement['arHandle']}' of this page, not '{$areaHandle}'. Nothing was "
                    . "changed. Use areaHandle '{$placement['arHandle']}'.";
            }
        }

        return self::notInNewest($cID, $recent, $blockID, $placements[0])
            . 'Read the newest version with get_page_blocks (it reads the newest version) to check. Nothing was changed.';
    }

    /**
     * For the block read tools (BF-10): whether the block is in the page's newest version, the one the write tools
     * change, and if not, where it is and which ID the newest version has in its place. Null when the block is not on
     * the page.
     */
    public static function readNote(int $cID, int $blockID): ?string
    {
        $recent = PageVersionRead::newestVersionID($cID);
        $placements = self::placements($cID, $blockID);
        if ($recent === 0 || $placements === []) {
            return null;
        }

        foreach ($placements as $placement) {
            if ((int) $placement['cvID'] === $recent) {
                $state = $recent === PageVersionRead::publishedVersionID($cID) ? 'published' : 'unpublished';

                return "Block {$blockID} is in the newest version ({$recent}, {$state}), the version editors see and the write tools change.";
            }
        }

        return trim(self::notInNewest($cID, $recent, $blockID, $placements[0]));
    }

    /**
     * @return list<array<string, mixed>> the block's placements on the page, newest version first
     */
    private static function placements(int $cID, int $blockID): array
    {
        return self::db()->fetchAllAssociative(
            'SELECT cvID, arHandle, cbDisplayOrder FROM CollectionVersionBlocks WHERE cID = ? AND bID = ? ORDER BY cvID DESC',
            [$cID, $blockID]
        );
    }

    /**
     * @param array<string, mixed> $old the block's newest placement, in a version older than $recent
     */
    private static function notInNewest(int $cID, int $recent, int $blockID, array $old): string
    {
        $db = self::db();
        $active = PageVersionRead::publishedVersionID($cID);
        $where = (int) $old['cvID'] === $active ? 'the published version' : "version {$old['cvID']}";
        $by = PageVersionRead::savedBy($cID, $recent);

        $message = "Block {$blockID} is in {$where} of this page, not in its newest version (version {$recent}, unpublished{$by}), "
            . 'which is the version the write tools edit. A change there, in edit mode or by the assistant, copied the block '
            . 'under a new ID. ';

        $replacement = $db->fetchAssociative(
            'SELECT cvb.bID, bt.btHandle FROM CollectionVersionBlocks cvb JOIN Blocks b ON b.bID = cvb.bID '
            . 'JOIN BlockTypes bt ON bt.btID = b.btID WHERE cvb.cID = ? AND cvb.cvID = ? AND cvb.arHandle = ? AND cvb.cbDisplayOrder = ?',
            [$cID, $recent, (string) $old['arHandle'], (int) $old['cbDisplayOrder']]
        );
        if ($replacement) {
            $message .= "At the same position in area '{$old['arHandle']}' the newest version has block {$replacement['bID']} "
                . "({$replacement['btHandle']}): if that is the block you mean, use block {$replacement['bID']}. ";
        } else {
            $message .= "The newest version has no block at the same position in area '{$old['arHandle']}'. ";
        }

        return $message;
    }

    private static function recentVersionID(Page $page): int
    {
        return PageVersionRead::newestVersionID((int) $page->getCollectionID());
    }

    private static function db(): Connection
    {
        return Application::getFacadeApplication()->make(Connection::class);
    }
}
