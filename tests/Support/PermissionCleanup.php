<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Database\Connection\Connection;

/**
 * P1.12: removes permission rows that a test created, in the order the foreign keys and Concrete's own joins need.
 * Shared by the fixtures that make accesses (TaskKeyFixture, AreaGrantFixture).
 */
final class PermissionCleanup
{
    /** Tables that hold assignments of an access to something (a key, a page, an area, a block, a file...). */
    private const ASSIGNMENT_TABLES = [
        'PermissionAssignments', 'PagePermissionAssignments', 'AreaPermissionAssignments', 'BlockPermissionAssignments',
        'FilePermissionAssignments', 'PageTypePermissionAssignments', 'TreeNodePermissionAssignments',
    ];

    /**
     * Deletes an access with its access list and the block type lists of an area access.
     */
    public static function deleteAccess(Connection $db, int $paID): void
    {
        foreach (['AreaPermissionBlockTypeAccessList', 'AreaPermissionBlockTypeAccessListCustom', 'PermissionAccessList'] as $table) {
            $db->executeStatement('DELETE FROM `' . $table . '` WHERE paID = ?', [$paID]);
        }
        $db->executeStatement('DELETE FROM PermissionAccess WHERE paID = ?', [$paID]);
    }

    /**
     * True when any key, page, area, block, file, page type or tree node still uses the access.
     */
    public static function isAssigned(Connection $db, int $paID): bool
    {
        foreach (self::ASSIGNMENT_TABLES as $table) {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM `' . $table . '` WHERE paID = ?', [$paID]) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes an access only when nothing uses it any more.
     */
    public static function deleteAccessIfUnused(Connection $db, int $paID): bool
    {
        if (self::isAssigned($db, $paID)) {
            return false;
        }
        self::deleteAccess($db, $paID);

        return true;
    }
}
