<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\Package as PackageEntity;
use Concrete\Core\Http\Request;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\View\PageView;
use Concrete\Core\Permission\Access\Entity\GroupEntity;
use Concrete\Core\Permission\Key\Key;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Access\NeuronPermissions;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Extension\Tier;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\TaskKeyFixture;
use Katalysis\NeuronAi\Tests\Support\ToolBaseline;
use Katalysis\NeuronAi\Tools\AttributesToolkit;
use Katalysis\NeuronAi\Tools\ToolTiers;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * P3.2-1 (B-ACC-9, B-ACC-2, B-T2-6): the tiers are Concrete task permission keys. `use_neuron_ai` (P1.10a) is Tier 1,
 * `neuron_ai_tier_configure` Tier 2 and `neuron_ai_tier_generate` Tier 3; tiers are cumulative and the super user is
 * Tier 3. A tool is offered only if the user's tier allows it, and the attribute definition, attribute set and topic
 * tree tools are Tier 2.
 *
 * Throwaway users and groups come from TaskKeyFixture and are removed afterwards, with each key's assignment put back.
 * The upgrade test deletes the two tier keys and puts the original rows back. Needs the seeded, upgraded dev site.
 * Nothing calls the AI provider.
 */
final class TierAccessTest extends ConcreteTestCase
{
    private const TIER_HANDLES = [NeuronPermissions::TIER_CONFIGURE_HANDLE, NeuronPermissions::TIER_GENERATE_HANDLE];

    /** B-T2-6: the core tools that leave Tier 1. */
    private const CONFIGURE_TOOLS = [
        'add_attribute_to_set',
        'create_attribute_set',
        'create_file_attribute',
        'create_page_attribute',
        'create_page_attribute_and_add_to_set',
        'create_topic',
        'create_topic_tree',
        'create_user_attribute',
        'delete_attribute_set',
        'delete_topic',
        'remove_attribute_from_set',
        'rename_topic',
        'update_file_select_attribute_options',
        'update_page_select_attribute_options',
        'update_user_select_attribute_options',
    ];

    private TaskKeyFixture $fixture;

    /** @var array{keys: list<array<string, mixed>>, assignments: list<array<string, mixed>>}|null */
    private ?array $keySnapshot = null;

    protected function setUp(): void
    {
        $this->fixture = new TaskKeyFixture($this->app());
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        $this->fixture->restore();
        $this->restoreOriginalKeys();
        $this->fixture->flush();
    }

    // ---- The keys ----------------------------------------------------------

    public function testTheTierKeysExistInTheAdminCategoryAndBelongToThePackage(): void
    {
        $packageID = (int) $this->db()->fetchOne('SELECT pkgID FROM Packages WHERE pkgHandle = ?', ['katalysis_neuron_ai']);
        $expected = [
            NeuronPermissions::TIER_CONFIGURE_HANDLE => 'Neuron AI tier 2: Configure',
            NeuronPermissions::TIER_GENERATE_HANDLE => 'Neuron AI tier 3: Generate',
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

    // ---- Each user's tier --------------------------------------------------

    public function testEachUsersTierIsTheHighestKeyTheyHoldAndTheSuperUserIsTierThree(): void
    {
        $content = $this->userHolding(NeuronPermissions::USE_HANDLE, 'content');
        $configure = $this->userHolding(NeuronPermissions::TIER_CONFIGURE_HANDLE, 'configure');
        $generate = $this->userHolding(NeuronPermissions::TIER_GENERATE_HANDLE, 'generate');

        $expected = [
            'guest' => [0, 'guest'],
            'neuron_editor (the no-access fixture)' => [0, 'neuron_editor'],
            'neuron_content_editor (the seeded Content-tier user)' => [1, 'neuron_content_editor'],
            'the use key' => [1, $content],
            'the Configure key only' => [2, $configure],
            'the Generate key only' => [3, $generate],
            'the super user' => [3, 'superuser'],
        ];
        foreach ($expected as $label => [$tier, $who]) {
            $this->actAs($who);
            $this->assertSame($tier, NeuronPermissions::tier(), $label);
            $this->assertSame($tier >= 1, ChatAccess::canUse(), "{$label}: any tier's key lets the user in (cumulative)");
        }
    }

    public function testGuestsGetNoTierEvenIfTheTierKeysAreGrantedToTheGuestGroup(): void
    {
        $guests = Group::getByID(GUEST_GROUP_ID);
        foreach (self::TIER_HANDLES as $handle) {
            $this->fixture->grantToGroup($handle, $guests);
        }

        $this->actAs('guest');
        $this->assertSame(0, NeuronPermissions::tier());
        $this->assertFalse(ChatAccess::canUse());
    }

    public function testTheSuperUserIsTierThreeWithEveryTierAssignmentEmptied(): void
    {
        foreach ([NeuronPermissions::USE_HANDLE, ...self::TIER_HANDLES] as $handle) {
            $this->fixture->emptyAssignment($handle);
        }

        $this->actAs('superuser');
        $this->assertSame(3, NeuronPermissions::tier());
    }

    // ---- The panel (Glyn, 28 October 2026: only where the toolbar shows) ----

    public function testAConfigureKeyHolderGetsThePanelOnlyWhereConcreteShowsThemTheToolbar(): void
    {
        $user = $this->userHolding(NeuronPermissions::TIER_CONFIGURE_HANDLE, 'toolbarless');
        $this->actAs($user);
        $home = Page::getByPath('/');

        $this->assertTrue(ChatAccess::canUse(), 'Precondition: a tier key lets the user in.');
        $this->assertFalse((bool) (new \Concrete\Core\Permission\Checker($home))->canViewToolbar(), 'Precondition: no toolbar on /.');
        $this->assertFalse(ChatAccess::canShowPanelOn(new PageView($home)), 'No toolbar, no panel: not everywhere.');
    }

    // ---- The tools each tier is offered -------------------------------------

    public function testEveryCoreToolHasATierAndTheConfigureToolsAreExactlyTheDefinitionTools(): void
    {
        $core = [];
        foreach (ToolBaseline::coreToolkits() as $toolkit) {
            if ($toolkit instanceof AttributesToolkit) {
                continue; // not registered by the agent (P1.8a); deleted in P3.3-3
            }
            foreach ($toolkit->tools() as $tool) {
                $core[] = $tool->getName();
            }
        }
        sort($core);
        $tiers = ToolTiers::all();
        $listed = array_keys($tiers);
        sort($listed);

        $this->assertSame($core, $listed, 'every core tool has a tier, and the map names no tool that does not exist');
        $configure = array_keys(array_filter($tiers, static fn (Tier $t): bool => $t === Tier::CONFIGURE));
        sort($configure);
        $this->assertSame(self::CONFIGURE_TOOLS, $configure);
        $this->assertSame([], array_keys(array_filter($tiers, static fn (Tier $t): bool => $t === Tier::GENERATE)), 'no Tier 3 core tools yet');
    }

    public function testATierOneUserIsNotOfferedTheDefinitionToolsAndATierTwoUserIs(): void
    {
        $this->actAs($this->userHolding(NeuronPermissions::USE_HANDLE, 'content'));
        $content = $this->offeredNames();
        $this->assertContains('set_page_attribute_value', $content, 'setting a value is Tier 1');
        $this->assertContains('list_topic_trees', $content, 'reading topic trees is Tier 1');
        $this->assertSame([], array_values(array_intersect(self::CONFIGURE_TOOLS, $content)), 'no Tier 2 tool at Tier 1');

        $this->actAs($this->userHolding(NeuronPermissions::TIER_CONFIGURE_HANDLE, 'configure'));
        $configure = $this->offeredNames();
        foreach (['create_page_attribute', 'create_attribute_set', 'create_topic_tree', 'rename_topic', 'set_page_attribute_value'] as $name) {
            $this->assertContains($name, $configure, "{$name} at Tier 2");
        }

        $this->actAs('superuser');
        $this->assertSame([], array_values(array_diff($configure, $this->offeredNames())), 'Tier 3 is offered everything Tier 2 is');
    }

    public function testAUserWithNoTierIsOfferedNoTools(): void
    {
        $this->actAs('neuron_editor');

        $this->assertSame([], $this->offeredNames());
    }

    // ---- An upgraded install -----------------------------------------------

    public function testAnUpgradeAddsTheTierKeysWithTheDefaultsAndNeverChangesAnAdminsChoices(): void
    {
        $this->deleteTierKeysAfterRecording();
        $this->actAs('superuser');
        $this->assertSame(3, NeuronPermissions::tier(), 'A missing key must not lock the super user out.');

        // package upgrade() runs this routine.
        $added = NeuronPermissions::install($this->packageEntity());
        $this->fixture->flush();

        $this->assertSame(self::TIER_HANDLES, $added);
        $this->assertSame([$this->administratorsEntityID()], $this->listedEntities(NeuronPermissions::TIER_CONFIGURE_HANDLE), 'Default: Administrators configure.');
        $this->assertFalse($this->hasAssignment(NeuronPermissions::TIER_GENERATE_HANDLE), 'Default: nobody but the super user generates.');

        $this->actAs('guest');
        $admin = $this->fixture->createUser('admin');
        $this->fixture->addToGroup($admin, Group::getByID(ADMIN_GROUP_ID));
        $this->actAs($admin);
        $this->assertSame(2, NeuronPermissions::tier(), 'A member of Administrators is Tier 2 by default.');

        // Running it again changes nothing, and an admin who emptied the Configure key does not get Administrators back.
        $this->assertSame([], NeuronPermissions::install($this->packageEntity()));
        $this->fixture->emptyAssignment(NeuronPermissions::TIER_CONFIGURE_HANDLE);
        $this->assertSame([], NeuronPermissions::install($this->packageEntity()));
        $this->assertFalse($this->hasAssignment(NeuronPermissions::TIER_CONFIGURE_HANDLE));
    }

    // ---- Helpers -----------------------------------------------------------

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * Throwaway users and groups are created as the guest: Concrete records who created a user or group, and a user
     * set with setCustomRequestUser() is not one Doctrine can persist that record against.
     */
    private function userHolding(string $handle, string $label): UserInfo
    {
        $this->actAs('guest');
        $user = $this->fixture->createUser($label);
        $group = $this->fixture->createGroup();
        $this->fixture->addToGroup($user, $group);
        $this->fixture->grantToGroup($handle, $group);

        return $user;
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

    /**
     * @return list<string>
     */
    private function offeredNames(): array
    {
        $names = [];
        foreach ((new ConcreteCmsAgent())->getTools() as $item) {
            foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                $names[] = $tool->getName();
            }
        }
        sort($names);

        return $names;
    }

    /**
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

    private function packageEntity(): PackageEntity
    {
        $pkg = Package::getByHandle('katalysis_neuron_ai');
        $this->assertInstanceOf(PackageEntity::class, $pkg, 'katalysis_neuron_ai is not installed.');

        return $pkg;
    }

    private function deleteTierKeysAfterRecording(): void
    {
        $keys = $this->db()->fetchAllAssociative('SELECT * FROM PermissionKeys WHERE pkHandle IN (?, ?) ORDER BY pkID', self::TIER_HANDLES);
        $this->assertCount(2, $keys, 'Both tier keys must exist before the upgrade test (run c5:package:update katalysis_neuron_ai).');
        $pkIDs = implode(',', array_map(static fn (array $row): int => (int) $row['pkID'], $keys));
        $assignments = $this->db()->fetchAllAssociative("SELECT * FROM PermissionAssignments WHERE pkID IN ({$pkIDs}) ORDER BY pkID");
        $this->keySnapshot = ['keys' => $keys, 'assignments' => $assignments];

        $this->db()->executeStatement("DELETE FROM PermissionAssignments WHERE pkID IN ({$pkIDs})");
        $this->db()->executeStatement("DELETE FROM PermissionKeys WHERE pkID IN ({$pkIDs})");
        $this->fixture->flush();
    }

    /**
     * Undoes deleteTierKeysAfterRecording(): removes the keys the test created, with the accesses their assignments
     * point to (they belong to the test), and writes the original rows back. The original accesses were never touched.
     */
    private function restoreOriginalKeys(): void
    {
        if ($this->keySnapshot === null) {
            return;
        }
        $snapshot = $this->keySnapshot;
        $this->keySnapshot = null;

        foreach (self::TIER_HANDLES as $handle) {
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
}
