<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Page\Page;

/**
 * The one way write tools get the version of a page to edit.
 *
 * Concrete keeps the Page::getByID($id, 'RECENT') object for the rest of the request, so after
 * a draft is made the cached page is still the old version. A second write that used the cached
 * page (or called getVersionToModify() on it, as Concrete's own AddBlockToPageCommand does)
 * would clone the old version again: a second draft, without the first write's edits.
 * Every write tool must call PageDraft::of() before it edits, and use the page it returns.
 *
 * Drafts belong to the page, not to a user: if the newest version is already a draft, it is
 * reused whoever made it.
 */
final class PageDraft
{
    /**
     * The version of the page to edit: the newest version if it is already a draft, otherwise a
     * new draft cloned from it. The result is also what Page::getByID($id, 'RECENT') returns
     * for the rest of the request.
     *
     * @param bool|null $created set to true when a new draft was made, false when one was reused
     */
    public static function of(Page $page, ?bool &$created = null): Page
    {
        // P3.6-2: an approval check's dry run stops here, before anything is written (CallCheck).
        if (self::$dryRun) {
            throw new DryRunReached('The call passed its checks; the dry run stops before the draft.');
        }

        $latest = Page::getByID($page->getCollectionID(), 'RECENT');
        $draft = $latest->getVersionToModify();
        $created = $draft !== $latest;
        if ($created) {
            self::cacheAsLatest($draft);
            // A call that fails later may discard this version, under DraftCleanup's rule (BF-3).
            DraftCleanup::created($draft);
        }

        return $draft;
    }

    /** P3.6-2: set while CallCheck runs a call's own checks; of() then throws DryRunReached instead of making a draft. */
    private static bool $dryRun = false;

    /**
     * Runs $call with the draft step stopped (CallCheck): every check a write tool makes before its draft runs, and
     * nothing is written.
     */
    public static function dryRun(callable $call): mixed
    {
        $outer = self::$dryRun;
        self::$dryRun = true;
        try {
            return $call();
        } finally {
            self::$dryRun = $outer;
        }
    }

    /**
     * P3.6-2: blocks copied into a draft during this request, by page: old block ID => the copy's ID. When several
     * approved calls in one response change the same block, the first copies it into the draft, and the others still
     * name the old ID, which the model couldn't know had changed.
     *
     * @var array<int, array<int, int>>
     */
    private static array $copies = [];

    /** Records that a block was copied into the page's draft (BlockWriteSupport::getBlockToEdit()). */
    public static function recordCopy(int $cID, int $oldBlockID, int $newBlockID): void
    {
        if ($oldBlockID !== $newBlockID) {
            self::$copies[$cID][$oldBlockID] = $newBlockID;
        }
    }

    /**
     * The block a write should use: the given ID, or, when it was copied into the draft earlier in this request and the
     * newest version no longer holds it in that area but holds its copy, the copy (followed through further copies).
     */
    public static function followCopies(Page $page, string $areaHandle, int $blockID): int
    {
        $cID = (int) $page->getCollectionID();
        $seen = [];
        $current = $blockID;
        while (isset(self::$copies[$cID][$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $current = self::$copies[$cID][$current];
        }
        if ($current === $blockID) {
            return $blockID;
        }

        $db = \Concrete\Core\Support\Facade\Application::getFacadeApplication()->make(\Concrete\Core\Database\Connection\Connection::class);
        $cvID = (int) $db->fetchOne('SELECT MAX(cvID) FROM CollectionVersions WHERE cID = ?', [$cID]);
        $holds = static fn (int $bID): bool => $db->fetchOne(
            'SELECT 1 FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? AND bID = ? AND arHandle = ?',
            [$cID, $cvID, $bID, $areaHandle]
        ) !== false;

        return !$holds($blockID) && $holds($current) ? $current : $blockID;
    }

    /**
     * Makes a draft that was made some other way (for example cloned from an older version) the
     * page that Page::getByID($id, 'RECENT') returns for the rest of the request.
     */
    public static function cacheAsLatest(Page $draft): void
    {
        CacheLocal::set('page', $draft->getCollectionID() . '/RECENT/' . Page::class, $draft);
    }
}
