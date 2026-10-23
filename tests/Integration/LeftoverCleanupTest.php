<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\LeftoverCleanup;

/**
 * P1.12: the selection and deletion behind documentation/fixtures/cleanup_test_leftovers.php, on a throwaway fixture
 * inside a transaction that is always rolled back. Whatever is already in the dev database does not matter: every
 * assertion is about the rows this test makes.
 */
final class LeftoverCleanupTest extends ConcreteTestCase
{
    private Connection $db;

    private const TESTS_SINCE = '2026-10-19';

    /** @var int[] */
    private array $areaKeys = [];

    private int $deadPageA = 2147480001;
    private int $deadPageB = 2147480002;

    protected function setUp(): void
    {
        $this->db = $this->app()->make(Connection::class);
        $this->areaKeys = array_map('intval', $this->db->fetchFirstColumn(
            "SELECT pk.pkID FROM PermissionKeys pk JOIN PermissionKeyCategories c ON c.pkCategoryID = pk.pkCategoryID
             WHERE c.pkCategoryHandle = 'area' ORDER BY pk.pkID"
        ));
        $this->assertNotEmpty($this->areaKeys);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
    }

    private function cleanup(): LeftoverCleanup
    {
        return new LeftoverCleanup($this->db, self::TESTS_SINCE);
    }

    private function newAccess(?int $entityID = null): int
    {
        $this->db->insert('PermissionAccess', ['paIsInUse' => 1]);
        $paID = (int) $this->db->lastInsertId();
        if ($entityID !== null) {
            $this->db->insert('PermissionAccessList', ['paID' => $paID, 'peID' => $entityID, 'pdID' => 0, 'accessType' => 10]);
        }

        return $paID;
    }

    private function newUserlessEntity(): int
    {
        $this->db->insert('PermissionAccessEntities', [
            'petID' => (int) $this->db->fetchOne("SELECT petID FROM PermissionAccessEntityTypes WHERE petHandle = 'user'"),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** The rows Area::overridePagePermissions() writes: area Main, every area key once, one access each. */
    private function overrideRows(int $cID, int $paID, ?array $keys = null): void
    {
        foreach ($keys ?? $this->areaKeys as $pkID) {
            $this->db->insert('AreaPermissionAssignments', ['cID' => $cID, 'arHandle' => 'Main', 'pkID' => $pkID, 'paID' => $paID]);
        }
    }

    public function testADeadPageWithTheOverrideSignatureIsSelectedWithItsAccessAndEntity(): void
    {
        $entityID = $this->newUserlessEntity();
        $paID = $this->newAccess($entityID);
        $this->overrideRows($this->deadPageA, $paID);

        $cleanup = $this->cleanup();
        $selection = $cleanup->select();

        $this->assertContains($this->deadPageA, $selection['selected']['pages']);
        $this->assertContains($paID, $selection['selected']['accesses']);
        $this->assertContains($entityID, $selection['selected']['userEntities']);

        $deleted = $cleanup->apply($selection);

        $this->assertGreaterThanOrEqual(count($this->areaKeys), $deleted['AreaPermissionAssignments']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM AreaPermissionAssignments WHERE cID = ?', [$this->deadPageA]));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM PermissionAccess WHERE paID = ?', [$paID]));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM PermissionAccessList WHERE paID = ?', [$paID]));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM PermissionAccessEntities WHERE peID = ?', [$entityID]));

        $again = $cleanup->select();
        $this->assertNotContains($this->deadPageA, $again['selected']['pages']);
        $this->assertNotContains($paID, $again['selected']['accesses']);
        $this->assertNotContains($entityID, $again['selected']['userEntities']);
    }

    public function testAPageNotHigherThanTheNewestPreTestPageIsReportedNotSelected(): void
    {
        $liveMax = (int) $this->db->fetchOne(
            "SELECT MAX(cID) FROM Collections WHERE cDateAdded < ?
             AND cID NOT IN (SELECT cID FROM PagePaths WHERE cPath = '/neuron-test' OR cPath LIKE '/neuron-test/%')",
            [self::TESTS_SINCE]
        );
        $gap = $this->db->fetchOne(
            'SELECT MIN(c.cID + 1) FROM Collections c WHERE c.cID + 1 < ? AND c.cID + 1 NOT IN (SELECT cID FROM Collections)',
            [$liveMax]
        );
        if ($gap === false || $gap === null) {
            $this->markTestSkipped('No unused cID below the newest pre-test page in this database.');
        }
        $gap = (int) $gap;
        $paID = $this->newAccess();
        $this->overrideRows($gap, $paID);

        $selection = $this->cleanup()->select();

        $this->assertNotContains($gap, $selection['selected']['pages']);
        $this->assertArrayHasKey($gap, $selection['evidence']['pagesNotSelected']);
        $this->assertNotContains($paID, $selection['selected']['accesses']);
    }

    public function testAPageWithoutTheFullSignatureIsReportedNotSelected(): void
    {
        $paID = $this->newAccess();
        $this->overrideRows($this->deadPageB, $paID, array_slice($this->areaKeys, 1));

        $selection = $this->cleanup()->select();

        $this->assertNotContains($this->deadPageB, $selection['selected']['pages']);
        $this->assertArrayHasKey($this->deadPageB, $selection['evidence']['pagesNotSelected']);
        $this->assertNotContains($paID, $selection['selected']['accesses']);
    }

    public function testAPageInTheCollectionsTableIsNeverSelected(): void
    {
        $livePage = (int) $this->db->fetchOne('SELECT MAX(cID) FROM Collections');
        $paID = $this->newAccess();
        $this->overrideRows($livePage, $paID);

        $selection = $this->cleanup()->select();

        $this->assertNotContains($livePage, $selection['selected']['pages']);
        $this->assertNotContains($paID, $selection['selected']['accesses']);
        $this->assertArrayNotHasKey($livePage, $selection['evidence']['pagesNotSelected'], 'A live page is not even a candidate');
    }

    public function testAnAccessThatAnythingElseUsesIsKeptWithItsEntity(): void
    {
        $entityID = $this->newUserlessEntity();
        $paID = $this->newAccess($entityID);
        $this->overrideRows($this->deadPageA, $paID);
        // A live page permission that points at the same access.
        $this->db->insert('PagePermissionAssignments', ['cID' => 1, 'pkID' => $this->areaKeys[0], 'paID' => $paID]);

        $selection = $this->cleanup()->select();

        $this->assertContains($this->deadPageA, $selection['selected']['pages'], 'The dead rows go');
        $this->assertNotContains($paID, $selection['selected']['accesses']);
        $this->assertArrayHasKey($paID, $selection['evidence']['accessesKept']);
        $this->assertNotContains($entityID, $selection['selected']['userEntities'], 'The entity is still used by the kept access');
        $this->assertArrayHasKey($entityID, $selection['evidence']['userEntitiesKept']);
    }

    public function testAnAccessUsedByAnotherPageOverrideIsKept(): void
    {
        $paID = $this->newAccess();
        $this->overrideRows($this->deadPageA, $paID);
        $this->db->insert('AreaPermissionAssignments', ['cID' => 1, 'arHandle' => 'Header', 'pkID' => $this->areaKeys[0], 'paID' => $paID]);

        $selection = $this->cleanup()->select();

        $this->assertNotContains($paID, $selection['selected']['accesses']);
        $this->assertArrayHasKey($paID, $selection['evidence']['accessesKept']);
    }

    public function testAnEntityWithAUserIsNeverSelected(): void
    {
        $entityID = (int) $this->db->fetchOne('SELECT peID FROM PermissionAccessEntityUsers LIMIT 1');
        $this->assertGreaterThan(0, $entityID);

        $selection = $this->cleanup()->select();

        $this->assertNotContains($entityID, $selection['selected']['userEntities']);
    }

    public function testOnlyDanglingSignupNotificationsAreSelected(): void
    {
        $this->db->insert('Notifications', ['nDate' => '2026-01-01 00:00:00', 'type' => 'usersignupnotification']);
        $dangling = (int) $this->db->lastInsertId();
        $this->db->insert('NotificationAlerts', ['naIsArchived' => 0, 'uID' => 1, 'nID' => $dangling]);
        $this->db->insert('NotificationAlerts', ['naIsArchived' => 0, 'uID' => 1, 'nID' => $dangling]);
        $this->db->insert('Notifications', ['nDate' => '2026-01-01 00:00:00', 'type' => 'groupsignupnotification']);
        $otherType = (int) $this->db->lastInsertId();
        $liveSignup = (int) $this->db->fetchOne('SELECT nID FROM UserSignupNotifications LIMIT 1');

        $cleanup = $this->cleanup();
        $selection = $cleanup->select();

        $this->assertContains($dangling, $selection['selected']['notifications']);
        $this->assertNotContains($otherType, $selection['selected']['notifications'], 'Other types are reported, not touched');
        if ($liveSignup > 0) {
            $this->assertNotContains($liveSignup, $selection['selected']['notifications']);
        }

        $deleted = $cleanup->apply($selection);

        $this->assertGreaterThanOrEqual(2, $deleted['NotificationAlerts']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM Notifications WHERE nID = ?', [$dangling]));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM NotificationAlerts WHERE nID = ?', [$dangling]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM Notifications WHERE nID = ?', [$otherType]));
    }

    public function testSummaryAndIsEmpty(): void
    {
        $paID = $this->newAccess();
        $this->overrideRows($this->deadPageA, $paID);
        $cleanup = $this->cleanup();
        $selection = $cleanup->select();

        $summary = LeftoverCleanup::summary($selection);
        $this->assertSame(count($selection['selected']['pages']), $summary['counts']['pages']);
        $this->assertFalse(LeftoverCleanup::isEmpty($selection));

        $cleanup->apply($selection);
        $this->assertNotContains($this->deadPageA, $cleanup->select()['selected']['pages']);
    }

    public function testIsEmptyIsTrueOnlyWhenNothingIsSelected(): void
    {
        $none = ['selected' => [
            'pages' => [], 'assignmentRows' => 0, 'accesses' => [], 'accessListRows' => 0,
            'accessChildRows' => 0, 'userEntities' => [], 'notifications' => [], 'alertRows' => 0,
        ]];
        $this->assertTrue(LeftoverCleanup::isEmpty($none));

        $some = $none;
        $some['selected']['userEntities'] = [5];
        $this->assertFalse(LeftoverCleanup::isEmpty($some));
    }

    /** A Blocks row of the content type that no page version uses, with its btContentLocal row. */
    private function unusedBlock(string $added): int
    {
        $this->db->insert('Blocks', [
            'bName' => '', 'bDateAdded' => $added, 'bDateModified' => $added, 'bFilename' => null, 'bIsActive' => 1,
            'btID' => (int) $this->db->fetchOne("SELECT btID FROM BlockTypes WHERE btHandle = 'content'"), 'uID' => 1,
        ]);
        $bID = (int) $this->db->lastInsertId();
        $this->db->insert('btContentLocal', ['bID' => $bID, 'content' => '<p>Left behind.</p>']);

        return $bID;
    }

    public function testAnUnusedBlockFromTheTestsIsSelectedWithItsContentRow(): void
    {
        $orphan = $this->unusedBlock(date('Y-m-d H:i:s'));
        $old = $this->unusedBlock('2026-01-01 10:00:00');
        $original = $this->unusedBlock(date('Y-m-d H:i:s'));
        // A scrapbook block that shows $original keeps it.
        $this->db->insert('btCoreScrapbookDisplay', ['bID' => 2147480050, 'bOriginalID' => $original]);

        $cleanup = $this->cleanup();
        $selection = $cleanup->select();

        $this->assertContains($orphan, $selection['selected']['orphanBlocks']);
        $this->assertNotContains($old, $selection['selected']['orphanBlocks']);
        $this->assertStringContainsString('before ' . self::TESTS_SINCE, $selection['evidence']['orphanBlocksKept'][$old]);
        $this->assertNotContains($original, $selection['selected']['orphanBlocks']);
        $this->assertStringContainsString('btCoreScrapbookDisplay.bOriginalID', $selection['evidence']['orphanBlocksKept'][$original]);
        $this->assertGreaterThanOrEqual(1, $selection['selected']['blockTypeRows']['btContentLocal'] ?? 0);

        $cleanup->apply($selection);

        $this->assertFalse($this->db->fetchOne('SELECT bID FROM Blocks WHERE bID = ?', [$orphan]));
        $this->assertFalse($this->db->fetchOne('SELECT bID FROM btContentLocal WHERE bID = ?', [$orphan]));
        $this->assertNotFalse($this->db->fetchOne('SELECT bID FROM Blocks WHERE bID = ?', [$old]), 'a block from before the tests is kept');
        $this->assertNotFalse($this->db->fetchOne('SELECT bID FROM Blocks WHERE bID = ?', [$original]), 'a referenced block is kept');
        $this->assertNotContains($orphan, $cleanup->select()['selected']['orphanBlocks']);
    }

    public function testABlockAPageUsesIsNeverSelected(): void
    {
        $used = (int) $this->db->fetchOne('SELECT bID FROM CollectionVersionBlocks ORDER BY bID DESC LIMIT 1');

        $this->assertNotContains($used, $this->cleanup()->select()['selected']['orphanBlocks']);
    }

    public function testAContentRowWithNoBlockIsSelected(): void
    {
        $this->db->insert('btContentLocal', ['bID' => 2147480060, 'content' => '<p>No block.</p>']);

        $cleanup = $this->cleanup();
        $selection = $cleanup->select();
        $this->assertContains(2147480060, $selection['selected']['contentRowsWithoutBlock']);

        $cleanup->apply($selection);
        $this->assertFalse($this->db->fetchOne('SELECT bID FROM btContentLocal WHERE bID = ?', [2147480060]));
    }

    public function testPageSummaryRowsOfADeletedTestPageAreSelected(): void
    {
        $template = $this->db->fetchOne('SELECT id FROM SummaryTemplates ORDER BY id LIMIT 1');
        if ($template === false) {
            $this->markTestSkipped('No summary template in this database.');
        }
        $this->db->insert('PageSummaryTemplates', ['template_id' => (int) $template, 'cID' => $this->deadPageA, 'data' => '{}']);
        $live = (int) $this->db->fetchOne('SELECT cID FROM Collections ORDER BY cID LIMIT 1');
        $this->db->insert('PageSummaryTemplates', ['template_id' => (int) $template, 'cID' => $live, 'data' => '{}']);

        $cleanup = $this->cleanup();
        $selection = $cleanup->select();
        $this->assertContains($this->deadPageA, $selection['selected']['summaryTemplatePages']);
        $this->assertNotContains($live, $selection['selected']['summaryTemplatePages'], 'a page that exists');

        $cleanup->apply($selection);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM PageSummaryTemplates WHERE cID = ?', [$this->deadPageA]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM PageSummaryTemplates WHERE cID = ? AND data = ?', [$live, '{}']));
    }
}
