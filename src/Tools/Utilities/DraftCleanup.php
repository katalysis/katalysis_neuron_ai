<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Log;

/**
 * A write tool that fails leaves the page as it found it (BF-3, plans/BF-3.md §4).
 *
 * Every page write tool runs inside run() (through its toolkit's guarded()). PageDraft::of() reports each version it
 * creates, and create_page each page it makes. When the call throws, a version is discarded only if:
 *
 * 1. this call created it (a draft that existed before the call is never touched, whoever made it);
 * 2. it is still the newest version of the page;
 * 3. it is still new and unapproved (edit mode's Save and any approval clear cvIsNew);
 * 4. it is unchanged since it was made: the per-version rows Concrete's Cloner copies and the version row match a
 *    fingerprint taken when it was made, and nobody has entered edit mode on the page since (the check-out row).
 *
 * A change by this call counts as a change too, so a partly written draft is kept and the error says so. A page
 * create_page made is deleted under the same rule, except that its own writes (the attributes it sets) re-take the
 * fingerprint, and it must still have its single version and no child pages.
 *
 * A call that succeeds but claims no draft (its result has no versionState) gets the same check, so a "success" that
 * changed nothing leaves nothing either.
 *
 * Between the last check and the discard an edit-mode save in another request could still land; Concrete takes no
 * lock we could share (accepted, plans/BF-3.md §4).
 */
final class DraftCleanup
{
    /** The tables keyed by a page version (cID, cvID) that fingerprint() reads. */
    public const VERSION_TABLES = [
        'BlockPermissionAssignments',
        'CollectionAttributeValues',
        'CollectionVersionAreaStyles',
        'CollectionVersionBlocks',
        'CollectionVersionBlocksCacheSettings',
        'CollectionVersionBlockStyles',
        'CollectionVersionRelatedEdits',
        'CollectionVersions',
        'CollectionVersionThemeCustomStyles',
        'PageTypeComposerOutputBlocks',
    ];

    /** Tables keyed by a page version that are not part of what it holds, and why. */
    public const NOT_VERSION_CONTENT = [
        'CollectionVersionBlocksOutputCache' => 'cached block output, written when a page is viewed and rebuilt on the next view',
    ];

    /**
     * The drafts and pages made by the call that is running, or null outside a call.
     *
     * @var list<array{kind: string, cID: int, cvID: int, fingerprint: array<string, mixed>}>|null
     */
    private static ?array $tracked = null;

    /**
     * Runs a tool call, and cleans up after it as described above.
     */
    public static function run(callable $call): mixed
    {
        $outer = self::$tracked;
        self::$tracked = [];
        try {
            $result = $call();
        } catch (\Throwable $e) {
            $tracked = self::$tracked;
            self::$tracked = $outer;

            throw self::afterFailure($tracked, $e);
        }
        $tracked = self::$tracked;
        self::$tracked = $outer;

        if (is_array($result) && !isset($result['versionState'])) {
            foreach ($tracked as $entry) {
                if (self::whyKept($entry) === null) {
                    self::remove($entry, 'it changed nothing');
                }
            }
        }

        return $result;
    }

    /**
     * Called by PageDraft::of() when it made a new version. Outside run() (create_page_draft) nothing is tracked.
     */
    public static function created(Page $draft): void
    {
        self::track('version', $draft);
    }

    /**
     * Called by create_page right after it made the page.
     */
    public static function createdPage(Page $page): void
    {
        self::track('page', $page);
    }

    /**
     * Called by create_page after each of its own writes to the page it made, so its own change doesn't count as
     * someone else's.
     */
    public static function ownChange(Page $page): void
    {
        if (self::$tracked === null) {
            return;
        }
        foreach (self::$tracked as $i => $entry) {
            if ($entry['kind'] === 'page' && $entry['cID'] === (int) $page->getCollectionID()) {
                self::$tracked[$i]['fingerprint'] = self::fingerprint($entry['cID'], $entry['cvID']);
            }
        }
    }

    private static function track(string $kind, Page $page): void
    {
        if (self::$tracked === null) {
            return;
        }
        $cID = (int) $page->getCollectionID();
        $cvID = (int) $page->getVersionID();
        self::$tracked[] = ['kind' => $kind, 'cID' => $cID, 'cvID' => $cvID, 'fingerprint' => self::fingerprint($cID, $cvID)];
    }

    /**
     * The exception to throw instead of $e: the same message, with what happened to what this call made.
     *
     * @param list<array{kind: string, cID: int, cvID: int, fingerprint: array<string, mixed>}> $tracked
     */
    private static function afterFailure(array $tracked, \Throwable $e): \Throwable
    {
        if ($tracked === []) {
            return $e;
        }

        $notes = [];
        $removedAll = true;
        foreach ($tracked as $entry) {
            $reason = self::whyKept($entry);
            if ($reason === null) {
                self::remove($entry, 'the call failed: ' . $e->getMessage());
                continue;
            }
            $removedAll = false;
            $notes[] = $entry['kind'] === 'page'
                ? "The page this call made (ID {$entry['cID']}) was kept because {$reason}."
                : "Version {$entry['cvID']}, which this call made, was kept because {$reason}." . self::blockNote($entry);
        }

        $message = rtrim($e->getMessage());
        if ($removedAll) {
            if (stripos($message, 'Nothing was changed') === false) {
                $message .= ' Nothing was changed.';
            }
        } else {
            $message = trim(str_ireplace('Nothing was changed.', '', $message)) . ' ' . implode(' ', $notes);
        }

        return new \RuntimeException($message, 0, $e);
    }

    /**
     * Why the version (or page) must stay, or null when it may go.
     *
     * @param array{kind: string, cID: int, cvID: int, fingerprint: array<string, mixed>} $entry
     */
    private static function whyKept(array $entry): ?string
    {
        $db = self::db();
        $cID = $entry['cID'];
        $cvID = $entry['cvID'];

        $row = $db->fetchAssociative('SELECT cvIsNew, cvIsApproved FROM CollectionVersions WHERE cID = ? AND cvID = ?', [$cID, $cvID]);
        if (!$row) {
            return 'it no longer exists';
        }
        if ((int) $db->fetchOne('SELECT MAX(cvID) FROM CollectionVersions WHERE cID = ?', [$cID]) !== $cvID) {
            return 'a newer version exists';
        }
        if ($entry['kind'] === 'version' && ((int) $row['cvIsNew'] !== 1 || (int) $row['cvIsApproved'] !== 0)) {
            return 'it has been saved or approved since';
        }
        if ($entry['kind'] === 'page' && (int) $db->fetchOne('SELECT COUNT(*) FROM Pages WHERE cParentID = ?', [$cID]) > 0) {
            return 'it has child pages';
        }

        $now = self::fingerprint($cID, $cvID);
        if ($now['checkout'] !== $entry['fingerprint']['checkout']) {
            return 'it is open in edit mode';
        }
        if ($now !== $entry['fingerprint']) {
            return 'it has changes';
        }

        return null;
    }

    /**
     * @param array{kind: string, cID: int, cvID: int, fingerprint: array<string, mixed>} $entry
     */
    private static function remove(array $entry, string $why): void
    {
        $page = Page::getByID($entry['cID'], (string) $entry['cvID']);
        if (!$page || $page->isError()) {
            return;
        }

        if ($entry['kind'] === 'page') {
            $page->delete();
            // Page::delete() leaves this row behind when Concrete made one (ThrowawayPages::restore() does the same).
            self::db()->executeStatement('DELETE FROM PageSummaryTemplates WHERE cID = ?', [$entry['cID']]);
        } else {
            $version = $page->getVersionObject();
            if (!$version || !$version->canDiscard()) {
                return;
            }
            // Concrete's own discard, as edit mode's "Discard" does.
            $version->discard();
        }

        // The request's page cache still holds the version as the newest (PageDraft::cacheAsLatest()); the next call
        // in this request must see the version that really is newest now.
        CacheLocal::flush();

        Log::info(sprintf(
            'Katalysis Neuron AI: removed the unused %s %d of page %d (BF-3): %s',
            $entry['kind'] === 'page' ? 'page, version' : 'version',
            $entry['cvID'],
            $entry['cID'],
            $why
        ));
    }

    /**
     * For a kept version: the block IDs it holds now, so a retry uses the right ones.
     *
     * @param array{kind: string, cID: int, cvID: int, fingerprint: array<string, mixed>} $entry
     */
    private static function blockNote(array $entry): string
    {
        $rows = self::db()->fetchAllAssociative(
            'SELECT arHandle, bID FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? ORDER BY arHandle, cbDisplayOrder',
            [$entry['cID'], $entry['cvID']]
        );
        $was = [];
        foreach ($entry['fingerprint']['blocks'] as $block) {
            $was[$block['arHandle'] . '#' . $block['bID']] = true;
        }
        $new = [];
        foreach ($rows as $row) {
            if (!isset($was[$row['arHandle'] . '#' . $row['bID']])) {
                $new[] = "{$row['bID']} in '{$row['arHandle']}'";
            }
        }

        return $new === [] ? '' : ' Blocks with new IDs in that version: ' . implode(', ', $new) . '. Use those IDs.';
    }

    /**
     * What a version holds: every per-version row Concrete's Cloner::cloneCollectionVersion() copies (Concrete 9.5.5),
     * the version row's own fields, related edits (global areas), and the page's check-out row.
     *
     * @return array<string, mixed>
     */
    private static function fingerprint(int $cID, int $cvID): array
    {
        $db = self::db();
        $rows = static fn (string $sql): array => $db->fetchAllAssociative($sql, [$cID, $cvID]);

        return [
            'version' => $db->fetchAssociative(
                'SELECT cvName, cvHandle, cvDescription, cvDatePublic, pTemplateID, pThemeID, pThemeSkinIdentifier, cvPublishDate, cvPublishEndDate
                 FROM CollectionVersions WHERE cID = ? AND cvID = ?',
                [$cID, $cvID]
            ),
            'blocks' => $rows('SELECT bID, arHandle, cbDisplayOrder, cbRelationID, cbOverrideAreaPermissions, cbIncludeAll,
                cbOverrideBlockTypeCacheSettings, cbOverrideBlockTypeContainerSettings, cbEnableBlockContainer
                FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? ORDER BY arHandle, cbDisplayOrder, bID'),
            'blockStyles' => $rows('SELECT bID, arHandle, issID FROM CollectionVersionBlockStyles WHERE cID = ? AND cvID = ? ORDER BY bID, arHandle'),
            'blockCache' => $rows('SELECT bID, arHandle, btCacheBlockOutput, btCacheBlockOutputOnPost, btCacheBlockOutputForRegisteredUsers,
                btCacheBlockOutputLifetime FROM CollectionVersionBlocksCacheSettings WHERE cID = ? AND cvID = ? ORDER BY bID, arHandle'),
            'blockPermissions' => $rows('SELECT bID, paID, pkID FROM BlockPermissionAssignments WHERE cID = ? AND cvID = ? ORDER BY bID, pkID, paID'),
            'areaStyles' => $rows('SELECT arHandle, issID FROM CollectionVersionAreaStyles WHERE cID = ? AND cvID = ? ORDER BY arHandle'),
            'themeStyles' => $rows('SELECT pThemeID, scvlID, preset, sccRecordID FROM CollectionVersionThemeCustomStyles WHERE cID = ? AND cvID = ?'),
            'attributes' => $rows('SELECT akID, avID FROM CollectionAttributeValues WHERE cID = ? AND cvID = ? ORDER BY akID'),
            'composerBlocks' => $rows('SELECT arHandle, cbDisplayOrder, ptComposerFormLayoutSetControlID, bID FROM PageTypeComposerOutputBlocks
                WHERE cID = ? AND cvID = ? ORDER BY bID'),
            'relatedEdits' => $rows('SELECT cRelationID, cvRelationID FROM CollectionVersionRelatedEdits WHERE cID = ? AND cvID = ?'),
            // Entering edit mode sets these; the heartbeat only moves cCheckedOutDatetimeLastEdit.
            'checkout' => $db->fetchAssociative('SELECT cIsCheckedOut, cCheckedOutUID, cCheckedOutDatetime FROM Pages WHERE cID = ?', [$cID]),
        ];
    }

    private static function db(): Connection
    {
        return Application::getFacadeApplication()->make(Connection::class);
    }
}
