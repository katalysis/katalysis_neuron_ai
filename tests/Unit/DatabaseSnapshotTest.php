<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tests\Support\DatabaseSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * P1.12: the comparison behind the leak check. No Concrete, no database.
 */
final class DatabaseSnapshotTest extends TestCase
{
    public function testIdenticalSnapshotsHaveNoDifferences(): void
    {
        $a = new DatabaseSnapshot(['Pages' => 3, 'Logs' => 10], ['Config' => '111']);
        $b = new DatabaseSnapshot(['Pages' => 3, 'Logs' => 10], ['Config' => '111']);

        $this->assertSame([], DatabaseSnapshot::differences($a, $b));
    }

    public function testRowsAddedAndRemovedAreNamedWithTheirSize(): void
    {
        $before = new DatabaseSnapshot(['Pages' => 3, 'Users' => 5, 'Same' => 1]);
        $after = new DatabaseSnapshot(['Pages' => 12, 'Users' => 4, 'Same' => 1]);

        $this->assertSame(
            ['Pages: 3 -> 12 (+9 rows)', 'Users: 5 -> 4 (-1 rows)'],
            DatabaseSnapshot::differences($before, $after)
        );
    }

    public function testLogsIsTheOnlyAllowedTable(): void
    {
        $this->assertSame(['Logs'], array_keys(DatabaseSnapshot::IGNORED));
        $this->assertNotSame('', DatabaseSnapshot::IGNORED['Logs'], 'The allowance needs a reason');

        $before = new DatabaseSnapshot(['Logs' => 10, 'Pages' => 3]);
        $after = new DatabaseSnapshot(['Logs' => 500, 'Pages' => 3]);

        $this->assertSame([], DatabaseSnapshot::differences($before, $after));
    }

    public function testAChangedRowIsCaughtByTheChecksumWhenTheCountIsTheSame(): void
    {
        $before = new DatabaseSnapshot(['Config' => 40], ['Config' => '111']);
        $after = new DatabaseSnapshot(['Config' => 40], ['Config' => '222']);

        $this->assertSame(['Config: still 40 rows, but the content changed'], DatabaseSnapshot::differences($before, $after));
    }

    public function testACountChangeIsReportedOnceNotAlsoAsAContentChange(): void
    {
        $before = new DatabaseSnapshot(['PermissionAccess' => 10], ['PermissionAccess' => '1']);
        $after = new DatabaseSnapshot(['PermissionAccess' => 11], ['PermissionAccess' => '2']);

        $this->assertSame(['PermissionAccess: 10 -> 11 (+1 rows)'], DatabaseSnapshot::differences($before, $after));
    }

    public function testCreatedAndDroppedTablesAreReported(): void
    {
        $before = new DatabaseSnapshot(['Old' => 2]);
        $after = new DatabaseSnapshot(['New' => 1]);

        $this->assertSame(
            ['New: table was created (1 rows)', 'Old: table was dropped (had 2 rows)'],
            DatabaseSnapshot::differences($before, $after)
        );
    }

    public function testTheMessageNamesTheClassAndEveryTable(): void
    {
        $message = DatabaseSnapshot::message('Some\\LeakyTest', ['Pages: 3 -> 12 (+9 rows)', 'Users: 5 -> 4 (-1 rows)']);

        $this->assertStringContainsString('Some\\LeakyTest', $message);
        $this->assertStringContainsString('Pages: 3 -> 12 (+9 rows)', $message);
        $this->assertStringContainsString('Users: 5 -> 4 (-1 rows)', $message);
    }
}
