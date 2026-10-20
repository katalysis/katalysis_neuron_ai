<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Application\Application;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Permission\Access\Access;
use Concrete\Core\Permission\Access\Entity\GroupEntity;
use Concrete\Core\Permission\Key\Key;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\RegistrationServiceInterface;
use Concrete\Core\User\UserInfo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Throwaway users and groups, and temporary changes to a task permission key's access list, for tests of the
 * Neuron task permissions (P1.10a). restore() puts everything back exactly:
 *
 *  - a key's assignment row is recorded before the first change and written back (or removed, if it had none);
 *  - every access this fixture creates is deleted;
 *  - every user and group this fixture creates is deleted, with the access-entity row a group leaves behind.
 *
 * A group passed to grantToGroup() that this fixture did not create (the Guest group) is never deleted.
 * No change is ever made to an access that other assignments use: grants go into a copy that the fixture owns.
 */
final class TaskKeyFixture
{
    private Connection $db;

    /** @var UserInfo[] */
    private array $users = [];

    /** @var Group[] */
    private array $groups = [];

    /** @var array<string, array{assignment: array<string, mixed>|null, owned: int|null}> keyed by key handle */
    private array $keys = [];

    public function __construct(private readonly Application $app)
    {
        $this->db = $app->make(Connection::class);
    }

    public function createUser(string $label = 'user'): UserInfo
    {
        $unique = bin2hex(random_bytes(4));
        $info = $this->app->make(RegistrationServiceInterface::class)->create([
            'uName' => 'p110a_' . $label . '_' . $unique,
            'uEmail' => 'p110a_' . $label . '_' . $unique . '@neuron-test.invalid',
            'uPassword' => bin2hex(random_bytes(12)),
            'uIsValidated' => 1,
        ]);
        // RegistrationService::create() builds the user's sign-up record but never sets it on the user, so the
        // in-memory user has no sign-up and UserInfo::delete() cannot remove it (it fails on the sign-up
        // foreign key, or leaves the sign-up's notification behind). Reading the user again loads the sign-up.
        $this->app->make(EntityManagerInterface::class)->refresh($info->getEntityObject());
        $this->users[] = $info;

        return $info;
    }

    public function createGroup(): Group
    {
        $group = Group::add('P1.10a test group ' . bin2hex(random_bytes(4)), 'Throwaway group for a test. Safe to delete.');
        $this->groups[] = $group;

        return $group;
    }

    public function addToGroup(UserInfo $info, Group $group): void
    {
        $info->getUserObject()->enterGroup($group);
        $this->flush();
    }

    /**
     * Adds $group to the access list of the key (in an access this fixture owns).
     */
    public function grantToGroup(string $handle, Group $group): void
    {
        $key = $this->key($handle);
        $access = $this->ownedAccess($handle, $key);
        $access->addListItem(GroupEntity::getOrCreate($group));
        $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
        $this->flush();
    }

    public function revokeFromGroup(string $handle, Group $group): void
    {
        $key = $this->key($handle);
        $access = $this->ownedAccess($handle, $key);
        $access->removeListItem(GroupEntity::getOrCreate($group));
        $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
        $this->flush();
    }

    /**
     * Gives the key no access list at all (what "Configure Neuron AI" has by default): only the super user passes.
     */
    public function emptyAssignment(string $handle): void
    {
        $key = $this->key($handle);
        $this->remember($handle, $key);
        $this->db->executeStatement('DELETE FROM PermissionAssignments WHERE pkID = ?', [$key->getPermissionKeyID()]);
        $this->flush();
    }

    public function restore(): void
    {
        foreach ($this->keys as $handle => $record) {
            $pkID = (int) $this->db->fetchOne('SELECT pkID FROM PermissionKeys WHERE pkHandle = ?', [$handle]);
            if ($pkID > 0) {
                $this->db->executeStatement('DELETE FROM PermissionAssignments WHERE pkID = ?', [$pkID]);
                if ($record['assignment'] !== null) {
                    $this->db->insert('PermissionAssignments', $record['assignment']);
                }
            }
            if ($record['owned'] !== null) {
                $this->deleteAccess($record['owned']);
            }
        }
        $this->keys = [];

        foreach ($this->users as $info) {
            $info->delete();
        }
        $this->users = [];

        foreach ($this->groups as $group) {
            $peID = (int) $this->db->fetchOne('SELECT peID FROM PermissionAccessEntityGroups WHERE gID = ?', [$group->getGroupID()]);
            $group->delete();
            if ($peID > 0 && (int) $this->db->fetchOne('SELECT COUNT(*) FROM PermissionAccessList WHERE peID = ?', [$peID]) === 0) {
                $this->db->executeStatement('DELETE FROM PermissionAccessEntityGroups WHERE peID = ?', [$peID]);
                $this->db->executeStatement('DELETE FROM PermissionAccessEntities WHERE peID = ?', [$peID]);
            }
        }
        $this->groups = [];

        $this->flush();
    }

    /**
     * Removes an access that the fixture owns: its list items, then the access.
     */
    public function deleteAccess(int $paID): void
    {
        PermissionCleanup::deleteAccess($this->db, $paID);
    }

    /** Permission answers are cached for the request. */
    public function flush(): void
    {
        $this->app->make('cache/request')->flush();
    }

    private function key(string $handle): Key
    {
        $this->flush();
        $key = Key::getByHandle($handle);
        if (!$key instanceof Key) {
            throw new \RuntimeException("The permission key {$handle} does not exist. Run c5:package:update katalysis_neuron_ai.");
        }

        return $key;
    }

    private function remember(string $handle, Key $key): void
    {
        if (!isset($this->keys[$handle])) {
            $row = $this->db->fetchAssociative('SELECT * FROM PermissionAssignments WHERE pkID = ?', [$key->getPermissionKeyID()]);
            $this->keys[$handle] = ['assignment' => $row === false ? null : $row, 'owned' => null];
        }
    }

    private function ownedAccess(string $handle, Key $key): Access
    {
        $this->remember($handle, $key);
        if ($this->keys[$handle]['owned'] !== null) {
            return Access::getByID($this->keys[$handle]['owned'], $key);
        }

        $current = $key->getPermissionAccessObject();
        $owned = is_object($current) ? $current->duplicate() : Access::create($key);
        $this->keys[$handle]['owned'] = (int) $owned->getPermissionAccessID();

        return $owned;
    }
}
