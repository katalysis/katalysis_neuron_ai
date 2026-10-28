<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * P1.12 leak check. A snapshot of the dev database: the exact row count of every table, and a content checksum of
 * the tables where a test could change a row without changing the count (permissions and configuration).
 *
 * ConcreteTestCase takes one before each integration test class and compares after it. A class that leaves rows
 * behind fails, with the table names and the size of each change. The comparison is a pure function of two
 * snapshots (differences()), so it is tested without Concrete.
 */
final class DatabaseSnapshot
{
    /**
     * Tables a test run is allowed to change. Each needs a reason: this list is the whole of the allowance.
     */
    public const IGNORED = [
        'Logs' => 'Concrete writes log lines for the work tests do (page, block and user events); the table is append-only and nothing reads it back.',
    ];

    /**
     * Tables whose content is also compared, so a test that rewrites a row without adding or removing one still fails.
     */
    public const CHECKSUMMED = [
        'Config',
        'PermissionAssignments',
        'PermissionAccess',
        'PermissionAccessList',
        'PermissionAccessEntities',
        'PermissionAccessEntityUsers',
        'PermissionAccessEntityGroups',
        'AreaPermissionAssignments',
        'PagePermissionAssignments',
        // P3.6-2 (Glyn): the approval mechanism's tables (P3.6-1). A decided call or an advanced run changes a row in
        // place.
        'KatalysisNeuronAiApprovals',
        'KatalysisNeuronAiWorkflowStore',
    ];

    /**
     * @param array<string, int> $counts table => exact row count
     * @param array<string, string> $checksums table => CHECKSUM TABLE value
     */
    public function __construct(
        public readonly array $counts,
        public readonly array $checksums = [],
    ) {
    }

    public static function take(Connection $db): self
    {
        $tables = $db->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
        );

        $counts = [];
        if ($tables !== []) {
            $selects = [];
            foreach ($tables as $table) {
                $selects[] = "SELECT '" . $table . "' AS t, COUNT(*) AS n FROM `" . $table . '`';
            }
            foreach ($db->fetchAllAssociative(implode(' UNION ALL ', $selects)) as $row) {
                $counts[(string) $row['t']] = (int) $row['n'];
            }
        }

        $checksums = [];
        $present = array_values(array_filter(self::CHECKSUMMED, static fn (string $table): bool => isset($counts[$table])));
        if ($present !== []) {
            $quoted = array_map(static fn (string $table): string => '`' . $table . '`', $present);
            // MySQL reports the table names in lower case here, so map them back to the names we asked for.
            $byLower = array_combine(array_map('strtolower', $present), $present);
            foreach ($db->fetchAllAssociative('CHECKSUM TABLE ' . implode(', ', $quoted)) as $row) {
                $name = (string) $row['Table'];
                $name = strtolower(substr($name, (int) strrpos($name, '.') + 1));
                $checksums[$byLower[$name] ?? $name] = (string) $row['Checksum'];
            }
        }

        return new self($counts, $checksums);
    }

    /**
     * What changed between two snapshots, one line per table, ignoring the allowed tables.
     *
     * @return string[]
     */
    public static function differences(self $before, self $after): array
    {
        $lines = [];
        $tables = array_unique(array_merge(array_keys($before->counts), array_keys($after->counts)));
        sort($tables);

        foreach ($tables as $table) {
            if (isset(self::IGNORED[$table])) {
                continue;
            }
            $was = $before->counts[$table] ?? null;
            $now = $after->counts[$table] ?? null;
            if ($was === null) {
                $lines[] = "{$table}: table was created ({$now} rows)";
            } elseif ($now === null) {
                $lines[] = "{$table}: table was dropped (had {$was} rows)";
            } elseif ($was !== $now) {
                $lines[] = sprintf('%s: %d -> %d (%+d rows)', $table, $was, $now, $now - $was);
            } elseif (($before->checksums[$table] ?? null) !== ($after->checksums[$table] ?? null)) {
                $lines[] = "{$table}: still {$now} rows, but the content changed";
            }
        }

        return $lines;
    }

    /**
     * @param string[] $differences
     */
    public static function message(string $testClass, array $differences): string
    {
        return $testClass . ' changed the dev database and did not put it back:' . "\n  " . implode("\n  ", $differences)
            . "\nTests must remove every row they create. Page::delete() and UserInfo::delete() leave permission rows"
            . ' behind (see tests/Support/AreaGrantFixture.php and TaskKeyFixture.php).';
    }
}
