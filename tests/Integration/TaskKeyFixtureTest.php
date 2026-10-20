<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Access\NeuronPermissions;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\TaskKeyFixture;

/**
 * P1.12: TaskKeyFixture puts the database back exactly as it found it. A throwaway user that went through the
 * registration service leaves a sign-up, a notification and alerts; the fixture must remove all of them, not only
 * the user, and a grant must leave no access, access list row or access entity behind.
 *
 * This test found the leak (the fixture's SQL workaround removed the sign-up rows but not the notifications).
 */
final class TaskKeyFixtureTest extends ConcreteTestCase
{
    /** Every table the fixture can touch, and the ones a user, a group and a notification spread into. */
    private const TABLES = [
        'Users', 'UserGroups', 'Groups', 'UserSignups', 'UserSignupNotifications', 'Notifications', 'NotificationAlerts',
        'GroupSignups', 'PermissionAssignments', 'PermissionAccess', 'PermissionAccessList', 'PermissionAccessEntities',
        'PermissionAccessEntityUsers', 'PermissionAccessEntityGroups', 'UserSearchIndexAttributes', 'UserAttributeValues',
    ];

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $db = $this->app()->make(Connection::class);
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $db->fetchOne('SELECT COUNT(*) FROM `' . $table . '`');
        }

        return $counts;
    }

    public function testAThrowawayUserInAGroupWithAGrantLeavesNoRowsBehind(): void
    {
        $fixture = new TaskKeyFixture($this->app());
        $before = $this->counts();

        $user = $fixture->createUser('hygiene');
        $group = $fixture->createGroup();
        $fixture->addToGroup($user, $group);
        $fixture->grantToGroup(NeuronPermissions::USE_HANDLE, $group);
        $this->assertNotSame($before, $this->counts(), 'The fixture should have created rows to remove');

        $fixture->restore();

        $this->assertSame($before, $this->counts());
    }

    public function testAThrowawayUserWhoIsNeverPutInAGroupLeavesNoRowsBehind(): void
    {
        $fixture = new TaskKeyFixture($this->app());
        $before = $this->counts();

        $fixture->createUser('hygiene');
        $fixture->restore();

        $this->assertSame($before, $this->counts());
    }

    public function testEmptyingAKeyAssignmentIsPutBackAndLeavesNoRowsBehind(): void
    {
        $fixture = new TaskKeyFixture($this->app());
        $before = $this->counts();

        $fixture->emptyAssignment(NeuronPermissions::CONFIGURE_HANDLE);
        $fixture->restore();

        $this->assertSame($before, $this->counts());
    }
}
