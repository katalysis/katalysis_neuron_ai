<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * P1.12: finds, and removes, the rows that test runs before P1.12 left in the dev database.
 * The CLI is documentation/fixtures/cleanup_test_leftovers.php; this class is the selection and the deletion, so
 * the selection can be tested on a throwaway fixture inside a rolled-back transaction.
 *
 * Everything is selected by criteria, never by a list of IDs (see documentation/plans/P1.12.md, section 2.3):
 *
 *  1. Dead-page permission rows. A cID in AreaPermissionAssignments that is not in Collections, whose rows are
 *     exactly what Area::overridePagePermissions() writes (area "Main" only, every area permission key once), and
 *     that is higher than every page that was already there when the tests began (the highest cID with a
 *     cDateAdded before $testsSince, outside /neuron-test). Their accesses are removed only when no row in any
 *     table with a paID column (found from information_schema) refers to them outside the selected rows.
 *  2. User entities with no user: type user, no PermissionAccessEntityUsers row, and no row in any table with a
 *     peID column once the accesses of step 1 are gone.
 *  3. Sign-up notifications whose UserSignupNotifications row is gone, and their alerts.
 *  4. (BF-10) Orphan blocks: a Blocks row that no page version uses (no CollectionVersionBlocks row), added on or after
 *     $testsSince, and referred to by no other table with a bID column (block-type tables, named bt*, hold the
 *     block's own data and go with it), nor as a scrapbook block's original. Blocks from before the tests (41, 43, 54
 *     and 256 on the dev site, P1.12) are never selected.
 *  5. (BF-10) btContentLocal rows whose block is gone.
 *  6. (BF-10) PageSummaryTemplates rows whose page is gone (Page::delete() leaves them), for a cID higher than every
 *     page from before the tests, as in step 1.
 *
 * Never selected: Logs, the live fixture page, live notifications, any shared access.
 */
final class LeftoverCleanup
{
    /** Rows that belong to an access and go with it. Every other table with a paID column counts as a reference. */
    private const ACCESS_CHILD_TABLES = [
        'PermissionAccessList',
        'AreaPermissionBlockTypeAccessList',
        'AreaPermissionBlockTypeAccessListCustom',
    ];

    /** Subtype tables of Notifications are references; these two are the notification itself and its alerts. */
    private const NOTIFICATION_OWN_TABLES = ['Notifications', 'NotificationAlerts', 'UserSignupNotifications'];

    /**
     * @param string $testsSince YYYY-MM-DD: when the Neuron tests began. Pages added before it are real content.
     */
    public function __construct(private readonly Connection $db, private readonly string $testsSince)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function select(): array
    {
        $db = $this->db;

        $areaKeys = array_map('intval', $db->fetchFirstColumn(
            "SELECT pk.pkID FROM PermissionKeys pk JOIN PermissionKeyCategories c ON c.pkCategoryID = pk.pkCategoryID
             WHERE c.pkCategoryHandle = 'area' ORDER BY pk.pkID"
        ));
        $liveMax = (int) $db->fetchOne(
            "SELECT MAX(cID) FROM Collections WHERE cDateAdded < ?
             AND cID NOT IN (SELECT cID FROM PagePaths WHERE cPath = '/neuron-test' OR cPath LIKE '/neuron-test/%')",
            [$this->testsSince]
        );

        // ---- 1. Dead-page permission rows ----------------------------------------------------------------------
        $byPage = [];
        $rows = $db->fetchAllAssociative(
            'SELECT cID, arHandle, pkID, paID FROM AreaPermissionAssignments
             WHERE cID NOT IN (SELECT cID FROM Collections) ORDER BY cID, arHandle, pkID, paID'
        );
        foreach ($rows as $row) {
            $byPage[(int) $row['cID']][] = $row;
        }
        $pages = [];
        $rejectedPages = [];
        $selectedPaIDs = [];
        $assignmentRows = 0;
        foreach ($byPage as $cID => $pageRows) {
            $areas = array_values(array_unique(array_column($pageRows, 'arHandle')));
            $keys = array_map('intval', array_column($pageRows, 'pkID'));
            if ($areas !== ['Main']) {
                $rejectedPages[$cID] = 'not the override signature: areas are ' . implode(', ', $areas);
            } elseif ($keys !== $areaKeys) {
                $rejectedPages[$cID] = 'not the override signature: ' . count($keys) . ' rows for ' . count($areaKeys) . ' area permission keys';
            } elseif ($cID <= $liveMax) {
                $rejectedPages[$cID] = "not higher than page {$liveMax}, the newest page from before {$this->testsSince}, so it may predate the tests";
            } else {
                $pages[] = $cID;
                $assignmentRows += count($pageRows);
                foreach ($pageRows as $row) {
                    $selectedPaIDs[(int) $row['paID']] = true;
                }
            }
        }
        $paIDs = array_keys($selectedPaIDs);
        sort($paIDs);

        // ---- 1b. Their accesses, unless something else uses them -----------------------------------------------
        $accessesDeleted = [];
        $accessesKept = [];
        if ($paIDs !== []) {
            $referenced = [];
            foreach ($this->tablesWithColumn('paID') as $table) {
                if ($table === 'PermissionAccess' || in_array($table, self::ACCESS_CHILD_TABLES, true)) {
                    continue;
                }
                $sql = 'SELECT paID, COUNT(*) n FROM `' . $table . '` WHERE paID IN (' . $this->ids($paIDs) . ')';
                if ($table === 'AreaPermissionAssignments') {
                    $sql .= ' AND cID NOT IN (' . $this->ids($pages) . ')';
                }
                foreach ($db->fetchAllAssociative($sql . ' GROUP BY paID') as $row) {
                    $referenced[(int) $row['paID']][] = $table . ' (' . $row['n'] . ')';
                }
            }
            $existing = array_map('intval', $db->fetchFirstColumn('SELECT paID FROM PermissionAccess WHERE paID IN (' . $this->ids($paIDs) . ')'));
            foreach ($paIDs as $paID) {
                if (isset($referenced[$paID])) {
                    $accessesKept[$paID] = 'used by ' . implode(', ', $referenced[$paID]);
                } elseif (in_array($paID, $existing, true)) {
                    $accessesDeleted[] = $paID;
                }
            }
        }
        $accessListRows = 0;
        $childRows = 0;
        if ($accessesDeleted !== []) {
            $in = $this->ids($accessesDeleted);
            $accessListRows = (int) $db->fetchOne('SELECT COUNT(*) FROM PermissionAccessList WHERE paID IN (' . $in . ')');
            $childRows = (int) $db->fetchOne('SELECT COUNT(*) FROM AreaPermissionBlockTypeAccessList WHERE paID IN (' . $in . ')')
                + (int) $db->fetchOne('SELECT COUNT(*) FROM AreaPermissionBlockTypeAccessListCustom WHERE paID IN (' . $in . ')');
        }

        // ---- 2. User entities with no user ---------------------------------------------------------------------
        $entities = [];
        $entitiesKept = [];
        $userTypeID = (int) $db->fetchOne("SELECT petID FROM PermissionAccessEntityTypes WHERE petHandle = 'user'");
        $candidates = array_map('intval', $db->fetchFirstColumn(
            'SELECT e.peID FROM PermissionAccessEntities e WHERE e.petID = ?
             AND NOT EXISTS (SELECT 1 FROM PermissionAccessEntityUsers u WHERE u.peID = e.peID) ORDER BY e.peID',
            [$userTypeID]
        ));
        if ($candidates !== []) {
            $referenced = [];
            $paColumn = $this->tablesWithColumn('paID');
            foreach ($this->tablesWithColumn('peID') as $table) {
                if ($table === 'PermissionAccessEntities') {
                    continue;
                }
                $sql = 'SELECT peID, COUNT(*) n FROM `' . $table . '` WHERE peID IN (' . $this->ids($candidates) . ')';
                if ($accessesDeleted !== [] && in_array($table, $paColumn, true)) {
                    $sql .= ' AND paID NOT IN (' . $this->ids($accessesDeleted) . ')';
                }
                foreach ($db->fetchAllAssociative($sql . ' GROUP BY peID') as $row) {
                    $referenced[(int) $row['peID']][] = $table . ' (' . $row['n'] . ')';
                }
            }
            foreach ($candidates as $peID) {
                if (isset($referenced[$peID])) {
                    $entitiesKept[$peID] = 'used by ' . implode(', ', $referenced[$peID]);
                } else {
                    $entities[] = $peID;
                }
            }
        }

        // ---- 3. Dangling sign-up notifications -----------------------------------------------------------------
        $notifications = [];
        $notificationsKept = [];
        $dangling = array_map('intval', $db->fetchFirstColumn(
            "SELECT n.nID FROM Notifications n WHERE n.type = 'usersignupnotification'
             AND NOT EXISTS (SELECT 1 FROM UserSignupNotifications s WHERE s.nID = n.nID) ORDER BY n.nID"
        ));
        if ($dangling !== []) {
            $referenced = [];
            foreach ($this->tablesWithColumn('nID') as $table) {
                if (in_array($table, self::NOTIFICATION_OWN_TABLES, true)) {
                    continue;
                }
                foreach ($db->fetchFirstColumn('SELECT DISTINCT nID FROM `' . $table . '` WHERE nID IN (' . $this->ids($dangling) . ')') as $nID) {
                    $referenced[(int) $nID][] = $table;
                }
            }
            foreach ($dangling as $nID) {
                if (isset($referenced[$nID])) {
                    $notificationsKept[$nID] = 'used by ' . implode(', ', $referenced[$nID]);
                } else {
                    $notifications[] = $nID;
                }
            }
        }
        $alertRows = $notifications === [] ? 0
            : (int) $db->fetchOne('SELECT COUNT(*) FROM NotificationAlerts WHERE nID IN (' . $this->ids($notifications) . ')');

        // ---- 4. Orphan blocks ----------------------------------------------------------------------------------
        $blocks = [];
        $blocksKept = [];
        $blockTypeRows = [];
        $unused = $db->fetchAllAssociative(
            'SELECT b.bID, b.bDateAdded FROM Blocks b
             WHERE NOT EXISTS (SELECT 1 FROM CollectionVersionBlocks c WHERE c.bID = b.bID) ORDER BY b.bID'
        );
        if ($unused !== []) {
            $in = $this->ids(array_column($unused, 'bID'));
            $referenced = [];
            $blockTables = [];
            foreach ($this->tablesWithColumn('bID') as $table) {
                if (str_starts_with($table, 'bt')) {
                    $blockTables[] = $table;
                    continue;
                }
                if ($table === 'Blocks' || $table === 'CollectionVersionBlocks') {
                    continue;
                }
                foreach ($db->fetchAllAssociative('SELECT bID, COUNT(*) n FROM `' . $table . '` WHERE bID IN (' . $in . ') GROUP BY bID') as $row) {
                    $referenced[(int) $row['bID']][] = $table . ' (' . $row['n'] . ')';
                }
            }
            foreach ($db->fetchFirstColumn('SELECT DISTINCT bOriginalID FROM btCoreScrapbookDisplay WHERE bOriginalID IN (' . $in . ')') as $bID) {
                $referenced[(int) $bID][] = 'btCoreScrapbookDisplay.bOriginalID';
            }
            foreach ($unused as $row) {
                $bID = (int) $row['bID'];
                if ((string) $row['bDateAdded'] < $this->testsSince) {
                    $blocksKept[$bID] = 'added ' . $row['bDateAdded'] . ', before ' . $this->testsSince;
                } elseif (isset($referenced[$bID])) {
                    $blocksKept[$bID] = 'used by ' . implode(', ', $referenced[$bID]);
                } else {
                    $blocks[] = $bID;
                }
            }
            if ($blocks !== []) {
                foreach ($blockTables as $table) {
                    $n = (int) $db->fetchOne('SELECT COUNT(*) FROM `' . $table . '` WHERE bID IN (' . $this->ids($blocks) . ')');
                    if ($n > 0) {
                        $blockTypeRows[$table] = $n;
                    }
                }
            }
        }

        // ---- 5. Content rows with no block ---------------------------------------------------------------------
        $contentRows = array_map('intval', $db->fetchFirstColumn(
            'SELECT c.bID FROM btContentLocal c WHERE NOT EXISTS (SELECT 1 FROM Blocks b WHERE b.bID = c.bID) ORDER BY c.bID'
        ));

        // ---- 6. Page summary rows of deleted pages -------------------------------------------------------------
        $summaryPages = [];
        $summaryRows = 0;
        $summaryKept = ['pages' => 0, 'rows' => 0];
        foreach ($db->fetchAllAssociative(
            'SELECT t.cID, COUNT(*) n FROM PageSummaryTemplates t
             WHERE NOT EXISTS (SELECT 1 FROM Collections c WHERE c.cID = t.cID) GROUP BY t.cID ORDER BY t.cID'
        ) as $row) {
            if ((int) $row['cID'] > $liveMax) {
                $summaryPages[] = (int) $row['cID'];
                $summaryRows += (int) $row['n'];
            } else {
                $summaryKept['pages']++;
                $summaryKept['rows'] += (int) $row['n'];
            }
        }

        return [
            'selected' => [
                'pages' => $pages,
                'assignmentRows' => $assignmentRows,
                'accesses' => $accessesDeleted,
                'accessListRows' => $accessListRows,
                'accessChildRows' => $childRows,
                'userEntities' => $entities,
                'notifications' => $notifications,
                'alertRows' => $alertRows,
                'orphanBlocks' => $blocks,
                'blockTypeRows' => $blockTypeRows,
                'contentRowsWithoutBlock' => $contentRows,
                'summaryTemplatePages' => $summaryPages,
                'summaryTemplateRows' => $summaryRows,
            ],
            'evidence' => [
                'testsSince' => $this->testsSince,
                'liveMaxPageID' => $liveMax,
                'areaPermissionKeys' => count($areaKeys),
                'pagesNotSelected' => $rejectedPages,
                'accessesKept' => $accessesKept,
                'userEntitiesKept' => $entitiesKept,
                'notificationsKept' => $notificationsKept,
                'orphanBlocksKept' => $blocksKept,
                'summaryTemplatesKept' => $summaryKept,
                'otherNotificationTypes' => $db->fetchAllKeyValue("SELECT type, COUNT(*) FROM Notifications WHERE type <> 'usersignupnotification' GROUP BY type"),
                'liveSignupNotifications' => (int) $db->fetchOne(
                    "SELECT COUNT(*) FROM Notifications n WHERE n.type = 'usersignupnotification'
                     AND EXISTS (SELECT 1 FROM UserSignupNotifications s WHERE s.nID = n.nID)"
                ),
            ],
        ];
    }

    public static function isEmpty(array $selection): bool
    {
        foreach ($selection['selected'] as $value) {
            if ($value !== [] && $value !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deletes what select() chose. The caller owns the transaction.
     *
     * @param array<string, mixed> $selection the result of select()
     *
     * @return array<string, int> rows deleted per table
     */
    public function apply(array $selection): array
    {
        $db = $this->db;
        $s = $selection['selected'];
        $deleted = [];
        $run = function (string $label, string $sql) use ($db, &$deleted): void {
            $deleted[$label] = (int) $db->executeStatement($sql);
        };

        if ($s['pages'] !== []) {
            $run('AreaPermissionAssignments', 'DELETE FROM AreaPermissionAssignments WHERE cID IN (' . $this->ids($s['pages']) . ')');
        }
        if ($s['accesses'] !== []) {
            $in = $this->ids($s['accesses']);
            $run('AreaPermissionBlockTypeAccessList', 'DELETE FROM AreaPermissionBlockTypeAccessList WHERE paID IN (' . $in . ')');
            $run('AreaPermissionBlockTypeAccessListCustom', 'DELETE FROM AreaPermissionBlockTypeAccessListCustom WHERE paID IN (' . $in . ')');
            $run('PermissionAccessList', 'DELETE FROM PermissionAccessList WHERE paID IN (' . $in . ')');
            $run('PermissionAccess', 'DELETE FROM PermissionAccess WHERE paID IN (' . $in . ')');
        }
        if ($s['userEntities'] !== []) {
            $run('PermissionAccessEntities', 'DELETE FROM PermissionAccessEntities WHERE peID IN (' . $this->ids($s['userEntities']) . ')');
        }
        if ($s['notifications'] !== []) {
            $in = $this->ids($s['notifications']);
            $run('NotificationAlerts', 'DELETE FROM NotificationAlerts WHERE nID IN (' . $in . ')');
            $run('Notifications', 'DELETE FROM Notifications WHERE nID IN (' . $in . ')');
        }
        if ($s['orphanBlocks'] !== []) {
            $in = $this->ids($s['orphanBlocks']);
            foreach (array_keys($s['blockTypeRows']) as $table) {
                $run($table, 'DELETE FROM `' . $table . '` WHERE bID IN (' . $in . ')');
            }
            $run('Blocks', 'DELETE FROM Blocks WHERE bID IN (' . $in . ')');
        }
        if ($s['contentRowsWithoutBlock'] !== []) {
            $run('btContentLocal (no block)', 'DELETE FROM btContentLocal WHERE bID IN (' . $this->ids($s['contentRowsWithoutBlock']) . ')');
        }
        if ($s['summaryTemplatePages'] !== []) {
            $run('PageSummaryTemplates', 'DELETE FROM PageSummaryTemplates WHERE cID IN (' . $this->ids($s['summaryTemplatePages']) . ')');
        }

        return $deleted;
    }

    /**
     * The numbers a dry run reports and --apply must find again.
     *
     * @return array<string, mixed>
     */
    public static function summary(array $selection): array
    {
        $s = $selection['selected'];

        return $s + [
            'testsSince' => $selection['evidence']['testsSince'],
            'counts' => [
                'pages' => count($s['pages']),
                'accesses' => count($s['accesses']),
                'userEntities' => count($s['userEntities']),
                'notifications' => count($s['notifications']),
                'orphanBlocks' => count($s['orphanBlocks']),
                'contentRowsWithoutBlock' => count($s['contentRowsWithoutBlock']),
                'summaryTemplatePages' => count($s['summaryTemplatePages']),
            ],
        ];
    }

    /**
     * @return string[]
     */
    private function tablesWithColumn(string $column): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT c.table_name FROM information_schema.columns c
             JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = \'BASE TABLE\'
             WHERE c.table_schema = DATABASE() AND c.column_name = ? ORDER BY c.table_name',
            [$column]
        ));
    }

    /**
     * @param int[] $ids
     */
    private function ids(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }
}
