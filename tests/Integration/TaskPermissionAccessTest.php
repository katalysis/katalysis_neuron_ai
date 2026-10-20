<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\Package as PackageEntity;
use Concrete\Core\Http\Request;
use Concrete\Core\Package\Package;
use Concrete\Core\Permission\Access\Entity\GroupEntity;
use Concrete\Core\Permission\Key\Key;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Access\NeuronPermissions;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\TaskKeyFixture;

/**
 * P1.10a: access to Neuron is two Concrete task permissions, "Use Neuron AI assistant" and "Configure Neuron AI"
 * (src/Access/NeuronPermissions.php), set under Dashboard > System & Settings > Permissions & Access > Tasks.
 *
 * Checked here:
 *  - both keys exist, in the `admin` category (the only one the Tasks page lists), owned by this package;
 *  - the use key: a user in a group that holds it can use the assistant, one outside cannot;
 *  - the configure key is separate: having one does not give the other;
 *  - guests never get either, even if an admin grants the key to the Guest group (deliberate);
 *  - the super user always passes, with every assignment emptied and with the keys missing;
 *  - an upgrade of an existing install (the same routine package upgrade() calls) adds the keys with the defaults
 *    (Administrators use; nobody but the super user configures), and running it again never changes what an admin chose.
 *
 * Throwaway users and groups come from TaskKeyFixture and are removed afterwards, with each key's assignment put
 * back as it was. The upgrade test deletes the two keys and puts the original rows back. Needs the seeded, upgraded
 * dev site (neuron_editor must exist; the package must be at 3.1.0 or later).
 */
final class TaskPermissionAccessTest extends ConcreteTestCase
{
    private const NO_ACCESS_USER = 'neuron_editor';

    private TaskKeyFixture $fixture;

    /**
     * What the keys looked like before the upgrade test deleted them: the PermissionKeys rows and their assignment rows.
     *
     * @var array{keys: list<array<string, mixed>>, assignments: list<array<string, mixed>>}|null
     */
    private ?array $keySnapshot = null;

    protected function setUp(): void
    {
        $this->fixture = new TaskKeyFixture($this->app());
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        // The fixture goes first: it puts the (possibly new) keys' assignments back, and deletes what it owns.
        $this->fixture->restore();
        $this->restoreOriginalKeys();
        $this->fixture->flush();
    }

    // ---- Helpers -----------------------------------------------------------

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * @param UserInfo|string $who 'guest', 'superuser', a seeded user name or a throwaway user
     */
    private function actAs(UserInfo|string $who): void
    {
        $request = Request::getInstance();
        if ($who === 'guest') {
            $request->setCustomRequestUser(-1);
        } elseif ($who instanceof UserInfo) {
            $request->setCustomRequestUser($who);
        } else {
            $repository = $this->app()->make(UserInfoRepository::class);
            $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
            $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
            $request->setCustomRequestUser($info);
        }
        $this->fixture->flush();
    }

    private function canUse(): bool
    {
        return NeuronPermissions::userHas(NeuronPermissions::USE_HANDLE);
    }

    private function canConfigure(): bool
    {
        return NeuronPermissions::userHas(NeuronPermissions::CONFIGURE_HANDLE);
    }

    /**
     * The entity IDs in the key's access list, read from the tables (0 or no assignment means an empty list).
     *
     * @return list<int>
     */
    private function listedEntities(string $handle): array
    {
        $paID = (int) $this->db()->fetchOne(
            'SELECT ta.paID FROM PermissionAssignments ta JOIN PermissionKeys pk ON pk.pkID = ta.pkID WHERE pk.pkHandle = ?',
            [$handle]
        );
        if ($paID === 0) {
            return [];
        }

        return array_map('intval', $this->db()->fetchFirstColumn('SELECT peID FROM PermissionAccessList WHERE paID = ? ORDER BY peID', [$paID]));
    }

    private function hasAssignment(string $handle): bool
    {
        return (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM PermissionAssignments ta JOIN PermissionKeys pk ON pk.pkID = ta.pkID WHERE pk.pkHandle = ? AND ta.paID > 0',
            [$handle]
        ) > 0;
    }

    private function administratorsEntityID(): int
    {
        return (int) GroupEntity::getOrCreate(Group::getByID(ADMIN_GROUP_ID))->getAccessEntityID();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentRows(): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT ta.pkID, ta.paID FROM PermissionAssignments ta JOIN PermissionKeys pk ON pk.pkID = ta.pkID'
            . ' WHERE pk.pkHandle IN (?, ?) ORDER BY ta.pkID',
            [NeuronPermissions::USE_HANDLE, NeuronPermissions::CONFIGURE_HANDLE]
        );
    }

    private function packageEntity(): PackageEntity
    {
        $pkg = Package::getByHandle('katalysis_neuron_ai');
        $this->assertInstanceOf(PackageEntity::class, $pkg, 'katalysis_neuron_ai is not installed.');

        return $pkg;
    }

    private function deleteKeysAfterRecording(): void
    {
        $handles = [NeuronPermissions::USE_HANDLE, NeuronPermissions::CONFIGURE_HANDLE];
        $keys = $this->db()->fetchAllAssociative('SELECT * FROM PermissionKeys WHERE pkHandle IN (?, ?) ORDER BY pkID', $handles);
        $this->assertCount(2, $keys, 'Both keys must exist before the upgrade test (run c5:package:update katalysis_neuron_ai).');
        $pkIDs = array_map(static fn (array $row): int => (int) $row['pkID'], $keys);
        $assignments = $this->db()->fetchAllAssociative(
            'SELECT * FROM PermissionAssignments WHERE pkID IN (' . implode(',', $pkIDs) . ') ORDER BY pkID'
        );
        $this->keySnapshot = ['keys' => $keys, 'assignments' => $assignments];

        $this->db()->executeStatement('DELETE FROM PermissionAssignments WHERE pkID IN (' . implode(',', $pkIDs) . ')');
        $this->db()->executeStatement('DELETE FROM PermissionKeys WHERE pkID IN (' . implode(',', $pkIDs) . ')');
        $this->fixture->flush();
    }

    /**
     * Undoes deleteKeysAfterRecording(): removes the keys the test created, with the accesses their assignments
     * point to (they belong to the test), and writes the original rows back. The original accesses were never touched.
     */
    private function restoreOriginalKeys(): void
    {
        if ($this->keySnapshot === null) {
            return;
        }
        $snapshot = $this->keySnapshot;
        $this->keySnapshot = null;

        foreach ([NeuronPermissions::USE_HANDLE, NeuronPermissions::CONFIGURE_HANDLE] as $handle) {
            $pkID = (int) $this->db()->fetchOne('SELECT pkID FROM PermissionKeys WHERE pkHandle = ?', [$handle]);
            if ($pkID === 0) {
                continue;
            }
            $paID = (int) $this->db()->fetchOne('SELECT paID FROM PermissionAssignments WHERE pkID = ?', [$pkID]);
            $this->db()->executeStatement('DELETE FROM PermissionAssignments WHERE pkID = ?', [$pkID]);
            if ($paID > 0) {
                $this->fixture->deleteAccess($paID);
            }
            $this->db()->executeStatement('DELETE FROM PermissionKeys WHERE pkID = ?', [$pkID]);
        }
        foreach ($snapshot['keys'] as $row) {
            $this->db()->insert('PermissionKeys', $row);
        }
        foreach ($snapshot['assignments'] as $row) {
            $this->db()->insert('PermissionAssignments', $row);
        }
    }

    // ---- The keys ----------------------------------------------------------

    public function testBothKeysExistInTheAdminCategoryAndBelongToThePackage(): void
    {
        $packageID = (int) $this->db()->fetchOne('SELECT pkgID FROM Packages WHERE pkgHandle = ?', ['katalysis_neuron_ai']);
        $this->assertGreaterThan(0, $packageID);
        $expected = [
            NeuronPermissions::USE_HANDLE => 'Use Neuron AI assistant',
            NeuronPermissions::CONFIGURE_HANDLE => 'Configure Neuron AI',
        ];

        foreach ($expected as $handle => $name) {
            $key = Key::getByHandle($handle);
            $this->assertInstanceOf(Key::class, $key, "{$handle} is missing. Run c5:package:update katalysis_neuron_ai.");
            $this->assertSame('admin', $key->getPermissionKeyCategoryHandle(), 'The Tasks page lists only the admin category.');
            $row = $this->db()->fetchAssociative('SELECT pkName, pkgID FROM PermissionKeys WHERE pkHandle = ?', [$handle]);
            $this->assertSame($name, $row['pkName']);
            $this->assertSame($packageID, (int) $row['pkgID'], 'The key must belong to the package, so an uninstall removes it.');
        }
    }

    // ---- Use: yes and no ---------------------------------------------------

    public function testAUserInAGroupThatHoldsTheUseKeyCanUseTheAssistantAndAnotherUserCannot(): void
    {
        $member = $this->fixture->createUser('member');
        $outsider = $this->fixture->createUser('outsider');
        $group = $this->fixture->createGroup();
        $this->fixture->addToGroup($member, $group);

        $this->actAs($member);
        $this->assertFalse($this->canUse(), 'The group does not hold the key yet.');
        $this->assertFalse(ChatAccess::canUse());

        $this->fixture->grantToGroup(NeuronPermissions::USE_HANDLE, $group);

        $this->actAs($member);
        $this->assertTrue($this->canUse());
        $this->assertTrue(ChatAccess::canUse(), 'The panel and the endpoints ask ChatAccess, which asks the key.');
        $this->assertNull(ChatAccess::refusalReason(false, null));
        $this->assertNull(ChatAccess::refusalReason(true, ChatAccess::generateToken()));

        $this->actAs($outsider);
        $this->assertFalse(ChatAccess::canUse(), 'A user outside the group is refused.');
        $this->assertSame(ChatAccess::ACCESS_DENIED_ERROR, ChatAccess::refusalReason(false, null));

        $this->actAs(self::NO_ACCESS_USER);
        $this->assertFalse(ChatAccess::canUse(), 'neuron_editor is in no group: the no-access fixture.');

        $this->fixture->revokeFromGroup(NeuronPermissions::USE_HANDLE, $group);
        $this->actAs($member);
        $this->assertFalse(ChatAccess::canUse(), 'Taking the group out of the access list takes the access away.');
    }

    public function testTheSeededChatUserCanUseTheAssistantThroughTheTestGroup(): void
    {
        $this->actAs('neuron_chat_editor');
        $this->assertTrue(ChatAccess::canUse(), 'neuron_chat_editor must pass the check (run the seed script).');
        $this->assertFalse($this->canConfigure(), 'It has the use key only.');
    }

    // ---- Configure: yes and no ---------------------------------------------

    public function testTheConfigureKeyIsSeparateFromTheUseKey(): void
    {
        $useOnly = $this->fixture->createUser('useonly');
        $configureOnly = $this->fixture->createUser('configureonly');
        $both = $this->fixture->createUser('both');
        $none = $this->fixture->createUser('none');
        $useGroup = $this->fixture->createGroup();
        $configureGroup = $this->fixture->createGroup();
        $this->fixture->addToGroup($useOnly, $useGroup);
        $this->fixture->addToGroup($both, $useGroup);
        $this->fixture->addToGroup($configureOnly, $configureGroup);
        $this->fixture->addToGroup($both, $configureGroup);
        $this->fixture->grantToGroup(NeuronPermissions::USE_HANDLE, $useGroup);
        $this->fixture->grantToGroup(NeuronPermissions::CONFIGURE_HANDLE, $configureGroup);

        $expected = [
            'use only' => [$useOnly, true, false],
            'configure only' => [$configureOnly, false, true],
            'both' => [$both, true, true],
            'neither' => [$none, false, false],
        ];
        foreach ($expected as $label => [$info, $use, $configure]) {
            $this->actAs($info);
            $this->assertSame($use, $this->canUse(), "{$label}: use");
            $this->assertSame($configure, $this->canConfigure(), "{$label}: configure");
        }
    }

    // ---- Guests ------------------------------------------------------------

    public function testGuestsAreRefusedEvenIfTheKeysAreGrantedToTheGuestGroup(): void
    {
        $this->actAs('guest');
        $this->assertFalse($this->canUse());
        $this->assertFalse($this->canConfigure());

        $guests = Group::getByID(GUEST_GROUP_ID);
        $this->fixture->grantToGroup(NeuronPermissions::USE_HANDLE, $guests);
        $this->fixture->grantToGroup(NeuronPermissions::CONFIGURE_HANDLE, $guests);

        $this->actAs('guest');
        $this->assertTrue(
            (bool) Key::getByHandle(NeuronPermissions::USE_HANDLE)->validate(),
            'The setup is real: Concrete itself says a guest holds the key now.'
        );
        $this->assertFalse(ChatAccess::canUse(), 'Guests never get the assistant, whatever an admin grants (deliberate).');
        $this->assertFalse($this->canConfigure());
        $this->assertSame(ChatAccess::ACCESS_DENIED_ERROR, ChatAccess::refusalReason(false, null));
    }

    // ---- Super user --------------------------------------------------------

    public function testTheSuperUserAlwaysPassesEvenWithEveryAssignmentEmptied(): void
    {
        $this->fixture->emptyAssignment(NeuronPermissions::USE_HANDLE);
        $this->fixture->emptyAssignment(NeuronPermissions::CONFIGURE_HANDLE);

        $this->actAs('superuser');
        $this->assertTrue(ChatAccess::canUse());
        $this->assertTrue($this->canConfigure());

        $this->actAs(self::NO_ACCESS_USER);
        $this->assertFalse(ChatAccess::canUse());
        $this->assertFalse($this->canConfigure());
    }

    // ---- An upgraded install -----------------------------------------------

    public function testAnUpgradeOfAnExistingInstallAddsTheKeysWithTheDefaultsAndNeverChangesAnAdminsChoices(): void
    {
        $this->deleteKeysAfterRecording();
        $this->assertNull(Key::getByHandle(NeuronPermissions::USE_HANDLE));
        $this->assertNull(Key::getByHandle(NeuronPermissions::CONFIGURE_HANDLE));

        // The package files are new and package upgrade has not run yet: only the super user gets in.
        $this->actAs('superuser');
        $this->assertTrue(ChatAccess::canUse(), 'A missing key must not lock the super user out.');
        $this->assertTrue($this->canConfigure());
        $this->actAs(self::NO_ACCESS_USER);
        $this->assertFalse(ChatAccess::canUse());
        $this->assertFalse($this->canConfigure());
        $this->actAs('guest');
        $this->assertFalse(ChatAccess::canUse());

        // package upgrade() runs this routine.
        $added = NeuronPermissions::install($this->packageEntity());
        $this->fixture->flush();

        $this->assertSame([NeuronPermissions::USE_HANDLE, NeuronPermissions::CONFIGURE_HANDLE], $added);
        $packageID = (int) $this->db()->fetchOne('SELECT pkgID FROM Packages WHERE pkgHandle = ?', ['katalysis_neuron_ai']);
        foreach ([NeuronPermissions::USE_HANDLE, NeuronPermissions::CONFIGURE_HANDLE] as $handle) {
            $key = Key::getByHandle($handle);
            $this->assertInstanceOf(Key::class, $key);
            $this->assertSame('admin', $key->getPermissionKeyCategoryHandle());
            $this->assertSame($packageID, (int) $this->db()->fetchOne('SELECT pkgID FROM PermissionKeys WHERE pkHandle = ?', [$handle]));
        }
        $this->assertSame([$this->administratorsEntityID()], $this->listedEntities(NeuronPermissions::USE_HANDLE), 'Default: Administrators use the assistant.');
        $this->assertFalse($this->hasAssignment(NeuronPermissions::CONFIGURE_HANDLE), 'Default: nobody but the super user configures.');

        $admin = $this->fixture->createUser('admin');
        $this->fixture->addToGroup($admin, Group::getByID(ADMIN_GROUP_ID));
        $this->actAs($admin);
        $this->assertTrue($this->canUse(), 'A member of Administrators can use the assistant.');
        $this->assertFalse($this->canConfigure(), 'An administrator cannot configure it until an admin grants the key.');
        $this->actAs('superuser');
        $this->assertTrue($this->canConfigure());
        $this->fixture->restore();

        // Running the routine again changes nothing.
        $before = $this->assignmentRows();
        $this->assertSame([], NeuronPermissions::install($this->packageEntity()));
        $this->fixture->flush();
        $this->assertSame($before, $this->assignmentRows());
        $this->assertSame([$this->administratorsEntityID()], $this->listedEntities(NeuronPermissions::USE_HANDLE));

        // An admin's choices survive it: a group added to both keys...
        $group = $this->fixture->createGroup();
        $this->fixture->grantToGroup(NeuronPermissions::USE_HANDLE, $group);
        $this->fixture->grantToGroup(NeuronPermissions::CONFIGURE_HANDLE, $group);
        $groupEntityID = (int) GroupEntity::getOrCreate($group)->getAccessEntityID();
        $useBefore = $this->listedEntities(NeuronPermissions::USE_HANDLE);
        $configureBefore = $this->listedEntities(NeuronPermissions::CONFIGURE_HANDLE);
        $this->assertContains($groupEntityID, $useBefore);
        $this->assertSame([$groupEntityID], $configureBefore);
        $this->assertSame([], NeuronPermissions::install($this->packageEntity()));
        $this->assertSame($useBefore, $this->listedEntities(NeuronPermissions::USE_HANDLE));
        $this->assertSame($configureBefore, $this->listedEntities(NeuronPermissions::CONFIGURE_HANDLE));

        // ...and an admin who emptied the use key does not get Administrators put back.
        $this->fixture->emptyAssignment(NeuronPermissions::USE_HANDLE);
        $this->assertSame([], NeuronPermissions::install($this->packageEntity()));
        $this->assertFalse($this->hasAssignment(NeuronPermissions::USE_HANDLE));
    }
}
