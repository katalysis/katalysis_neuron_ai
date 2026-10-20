<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\RegistrationServiceInterface;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai\NeuronAi;
use Katalysis\NeuronAi\Settings\ApiKeySettings;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\InMemoryConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1.7b: saving the settings needs edit permission on the settings page ("Edit Page
 * Properties"), as well as the CSRF token. Viewing the page, which is all the chat
 * access rule asks for (B-ACC-1), is not enough.
 *
 * The view-only case is a throwaway user in the Administrators group: that group
 * can view the settings page (the page inherits "view" from /dashboard) and holds no
 * edit permission on it. No page permission is changed, so there is nothing to
 * restore. The user is deleted after each test.
 *
 * One test grants that throwaway user "Edit Page Properties" on the settings page.
 * The page may start in either state: inheriting from /dashboard (a plain site), or
 * already overriding (the seed gives neuron_chat_editor view on it, P1.10). The test
 * records the state it finds and its cleanup restores exactly that: if the page
 * inherited, it inherits again; if it overrode, only the throwaway user's assignment
 * and rows are removed and the page keeps overriding. The test proves it with before
 * and after snapshots. It never assumes or forces a starting state.
 *
 * Settings live in an in-memory config with dummy keys, so the dev site's real keys
 * are never read or changed, and nothing calls a provider.
 */
final class SettingsSavePermissionTest extends ConcreteTestCase
{
    private const DUMMY_OPEN_AI = 'sk-dummy-OPENAI-0123456789-abcdefghij-ZQ9X';
    private const DUMMY_ANTHROPIC = 'sk-ant-dummy-ANTHROPIC-9876543210-mnbvcxzlkj-WQ7P';
    private const DUMMY_OLLAMA = 'dummy-ollama-secret-1122334455-RT5K';

    private Repository $config;

    /** @var UserInfo[] throwaway users, deleted in tearDown() */
    private array $createdUsers = [];

    /** Set when a test grants a permission on the settings page, so tearDown() can undo it. */
    private bool $grantedOnSettingsPage = false;

    /**
     * The settings page's permission state before the test changed anything: its Pages row and its own
     * assignment rows. Recorded by recordSettingsPageState(), used by removeGrantFromSettingsPage().
     *
     * @var array{row: array<string, mixed>, assignments: list<array<string, mixed>>}|null
     */
    private ?array $settingsStart = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Concrete's router finds package controllers by path; here the controller is loaded directly.
        require_once dirname(__DIR__, 2) . '/controllers/single_page/dashboard/system/ai/neuron_ai.php';
    }

    protected function setUp(): void
    {
        InMemoryConfig::reset();
        $this->config = InMemoryConfig::repository();
        $this->config->save('katalysis.ai.open_ai_key', self::DUMMY_OPEN_AI);
        $this->config->save('katalysis.ai.anthropic_key', self::DUMMY_ANTHROPIC);
        $this->config->save('katalysis.ai.ollama_key', self::DUMMY_OLLAMA);
        $this->config->save('katalysis.ai.open_ai_model', 'original-model');
        $this->config->save('katalysis.ai.ollama_url', 'http://original.invalid');
        $this->app()->make('session')->getFlashBag()->clear();
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        Request::getInstance()->request->replace([]);
        // The grant goes first: it is found through the user, who must still exist.
        $this->removeGrantFromSettingsPage();
        foreach ($this->createdUsers as $info) {
            $info->delete();
        }
        $this->createdUsers = [];
        $this->settingsStart = null;
        InMemoryConfig::reset();
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * @param string $who 'guest', 'superuser', 'viewonly' (the throwaway user) or a seeded user name
     */
    private function actAs(string $who): void
    {
        $request = Request::getInstance();
        if ($who === 'guest') {
            $request->setCustomRequestUser(-1);
        } elseif ($who === 'viewonly') {
            $request->setCustomRequestUser($this->viewOnlyUser());
        } else {
            $repository = $this->app()->make(UserInfoRepository::class);
            $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
            $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
            $request->setCustomRequestUser($info);
        }
        // Permission answers are cached for the request.
        $this->app()->make('cache/request')->flush();
    }

    private function viewOnlyUser(): UserInfo
    {
        if ($this->createdUsers === []) {
            $unique = bin2hex(random_bytes(4));
            $info = $this->app()->make(RegistrationServiceInterface::class)->create([
                'uName' => 'p17b_viewonly_' . $unique,
                'uEmail' => 'p17b_viewonly_' . $unique . '@neuron-test.invalid',
                'uPassword' => bin2hex(random_bytes(12)),
                'uIsValidated' => 1,
            ]);
            $this->assertNotNull($info);
            $this->createdUsers[] = $info;
            $group = Group::getByID(ADMIN_GROUP_ID);
            $this->assertNotNull($group, 'The Administrators group is missing.');
            $info->getUserObject()->enterGroup($group);
        }

        return $this->createdUsers[0];
    }

    private function settingsPage(): Page
    {
        $page = Page::getByPath('/dashboard/system/ai/neuron_ai');
        $this->assertFalse($page->isError(), 'The settings page is missing. Is katalysis_neuron_ai installed?');

        return $page;
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * Grants "Edit Page Properties" on the settings page to the throwaway user.
     * The page starts to override its permissions; removeGrantFromSettingsPage() undoes that.
     */
    private function grantEditToViewOnlyUser(): void
    {
        $info = $this->viewOnlyUser();
        $this->recordSettingsPageState();
        $this->grantedOnSettingsPage = true;
        $this->settingsPage()->assignPermissions($info, ['edit_page_properties']);
        $this->forgetCachedPermissions();
    }

    /**
     * Records, once, the state the settings page is in before this test grants anything.
     *
     * @return array{row: array<string, mixed>, assignments: list<array<string, mixed>>}
     */
    private function recordSettingsPageState(): array
    {
        if ($this->settingsStart === null) {
            $cID = (int) $this->settingsPage()->getCollectionID();
            $this->settingsStart = [
                'row' => $this->db()->fetchAssociative(
                    'SELECT cInheritPermissionsFrom, cInheritPermissionsFromCID FROM Pages WHERE cID = ?',
                    [$cID]
                ),
                'assignments' => $this->db()->fetchAllAssociative(
                    'SELECT cID, pkID, paID FROM PagePermissionAssignments WHERE cID = ? ORDER BY pkID, paID',
                    [$cID]
                ),
            ];
        }

        return $this->settingsStart;
    }

    /**
     * Puts the settings page's permissions back to the state recorded before the grant, and removes the
     * permission rows that the throwaway user's grant created. Safe to call when nothing was granted.
     *
     * If the page was inheriting from its parent, it inherits again. If it was already overriding, it keeps
     * overriding, and only the assignment rows that were not there at the start are deleted. Only accesses
     * that list the throwaway user's access entity are removed, and only when nothing is assigned to them.
     */
    private function removeGrantFromSettingsPage(): void
    {
        if (!$this->grantedOnSettingsPage) {
            return;
        }
        $this->grantedOnSettingsPage = false;

        $db = $this->db();
        $peIDs = [];
        $paIDs = [];
        foreach ($this->createdUsers as $info) {
            foreach ($db->fetchFirstColumn('SELECT peID FROM PermissionAccessEntityUsers WHERE uID = ?', [$info->getUserID()]) as $peID) {
                $peIDs[] = (int) $peID;
                foreach ($db->fetchFirstColumn('SELECT DISTINCT paID FROM PermissionAccessList WHERE peID = ?', [$peID]) as $paID) {
                    $paIDs[] = (int) $paID;
                }
            }
        }

        $start = $this->recordSettingsPageState();
        if ($start['row']['cInheritPermissionsFrom'] === 'PARENT') {
            // Page::inheritPermissionsFromParent() also removes the page's own assignments and rescans its areas.
            $this->settingsPage()->inheritPermissionsFromParent();
        } else {
            // The page was overriding before the test: keep it that way and remove only the added assignments.
            $cID = (int) $this->settingsPage()->getCollectionID();
            $kept = [];
            foreach ($start['assignments'] as $row) {
                $kept[(int) $row['pkID'] . ':' . (int) $row['paID']] = true;
            }
            foreach ($db->fetchAllAssociative('SELECT pkID, paID FROM PagePermissionAssignments WHERE cID = ?', [$cID]) as $row) {
                if (!isset($kept[(int) $row['pkID'] . ':' . (int) $row['paID']])) {
                    $db->executeStatement(
                        'DELETE FROM PagePermissionAssignments WHERE cID = ? AND pkID = ? AND paID = ?',
                        [$cID, $row['pkID'], $row['paID']]
                    );
                }
            }
        }

        foreach (array_unique($paIDs) as $paID) {
            $inUse = 0;
            foreach ($this->assignmentTables() as $table) {
                $inUse += (int) $db->fetchOne("SELECT COUNT(*) FROM `{$table}` WHERE paID = ?", [$paID]);
            }
            if ($inUse === 0) {
                $db->executeStatement('DELETE FROM PermissionAccessList WHERE paID = ?', [$paID]);
                $db->executeStatement('DELETE FROM PermissionAccess WHERE paID = ?', [$paID]);
            }
        }
        foreach (array_unique($peIDs) as $peID) {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM PermissionAccessList WHERE peID = ?', [$peID]) === 0) {
                $db->executeStatement('DELETE FROM PermissionAccessEntityUsers WHERE peID = ?', [$peID]);
                $db->executeStatement('DELETE FROM PermissionAccessEntities WHERE peID = ?', [$peID]);
            }
        }
        $this->forgetCachedPermissions();
    }

    private function forgetCachedPermissions(): void
    {
        $this->settingsPage()->refreshCache();
        $this->app()->make('cache/request')->flush();
    }

    /**
     * @return string[] every table that assigns a permission access (paID) to an object
     */
    private function assignmentTables(): array
    {
        return array_map('strval', $this->db()->fetchFirstColumn(
            'SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'paID' AND TABLE_NAME LIKE '%PermissionAssignments'"
            . ' ORDER BY TABLE_NAME'
        ));
    }

    /**
     * The permission rows that matter here: the settings page and its parent in full, global row counts,
     * and the orphans (accesses nothing is assigned to, and entities nothing refers to).
     *
     * @return array<string, mixed>
     */
    private function permissionSnapshot(): array
    {
        $db = $this->db();
        $settingsID = (int) $this->settingsPage()->getCollectionID();
        $parentID = (int) $this->settingsPage()->getCollectionParentID();
        $this->assertGreaterThan(0, $settingsID);

        $snapshot = [
            'settingsPageID' => $settingsID,
            'pages' => $db->fetchAllAssociative(
                'SELECT cID, cInheritPermissionsFrom, cInheritPermissionsFromCID FROM Pages WHERE cID IN (?, ?, 2) ORDER BY cID',
                [$settingsID, $parentID]
            ),
            'pageAssignments' => $db->fetchAllAssociative(
                'SELECT cID, pkID, paID FROM PagePermissionAssignments WHERE cID IN (?, ?, 2) ORDER BY cID, pkID, paID',
                [$settingsID, $parentID]
            ),
            'areaAssignments' => $db->fetchAllAssociative(
                'SELECT cID, arHandle, pkID, paID FROM AreaPermissionAssignments WHERE cID = ? ORDER BY arHandle, pkID, paID',
                [$settingsID]
            ),
            'accessListOfAssignedAccesses' => $db->fetchAllAssociative(
                'SELECT paID, peID, accessType FROM PermissionAccessList WHERE paID IN'
                . ' (SELECT paID FROM PagePermissionAssignments WHERE cID IN (?, ?, 2)) ORDER BY paID, peID',
                [$settingsID, $parentID]
            ),
        ];

        foreach (['PermissionAccess', 'PermissionAccessList', 'PermissionAccessEntities', 'PermissionAccessEntityUsers', 'PagePermissionAssignments', 'AreaPermissionAssignments', 'Users', 'UserGroups'] as $table) {
            $snapshot['count ' . $table] = (int) $db->fetchOne("SELECT COUNT(*) FROM `{$table}`");
        }

        $assigned = [];
        foreach ($this->assignmentTables() as $table) {
            $assigned[] = "SELECT paID FROM `{$table}`";
        }
        $snapshot['orphan accesses'] = (int) $db->fetchOne('SELECT COUNT(*) FROM PermissionAccess WHERE paID NOT IN (' . implode(' UNION ', $assigned) . ')');
        $snapshot['orphan access list rows'] = (int) $db->fetchOne('SELECT COUNT(*) FROM PermissionAccessList WHERE paID NOT IN (SELECT paID FROM PermissionAccess)');
        $snapshot['orphan user entities'] = (int) $db->fetchOne('SELECT COUNT(*) FROM PermissionAccessEntityUsers WHERE uID NOT IN (SELECT uID FROM Users)');
        // Entities that list no user and no group, and that no access list uses: left behind by a deleted grant.
        $snapshot['unused user entities'] = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM PermissionAccessEntityUsers eu WHERE eu.peID NOT IN (SELECT peID FROM PermissionAccessList)'
        );

        return $snapshot;
    }

    private function controller(): NeuronAi
    {
        $controller = new class($this->settingsPage(), new ApiKeySettings($this->config)) extends NeuronAi {
            public function __construct(Page $page, private readonly ApiKeySettings $testSettings)
            {
                parent::__construct($page);
            }

            protected function settings(): ApiKeySettings
            {
                return $this->testSettings;
            }

            public function errorText(): string
            {
                return $this->error->toText();
            }
        };

        // What Concrete does before it calls an action: give the controller the application, then on_start().
        $controller->setApplication($this->app());
        $controller->on_start();

        return $controller;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(array $fields, bool $withToken): void
    {
        if ($withToken) {
            // The token belongs to the current user, so call this after actAs().
            $fields['ccm_token'] = $this->app()->make('token')->generate('ai.settings');
        }
        Request::getInstance()->request->replace($fields);
    }

    /**
     * A post that changes every setting, including a new key and a key removal.
     *
     * @return array<string, mixed>
     */
    private function changeEverything(): array
    {
        return [
            'open_ai_key' => 'sk-dummy-NEW-VALUE-4455',
            'remove_anthropic_key' => '1',
            'open_ai_model' => 'changed-model',
            'anthropic_model' => 'changed-anthropic-model',
            'ollama_url' => 'http://changed.invalid',
            'ollama_model' => 'changed-ollama-model',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsSnapshot(): array
    {
        return (array) $this->config->get('katalysis.ai');
    }

    private function renderSettingsPage(): string
    {
        $controller = $this->controller();
        $controller->view();

        $vars = $controller->getSets();
        $vars['controller'] = $controller;
        $viewFile = dirname(__DIR__, 2) . '/single_pages/dashboard/system/ai/neuron_ai.php';

        $render = static function (array $vars, string $viewFile): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            try {
                include $viewFile;

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };

        return $render($vars, $viewFile);
    }

    // ---- The fixture itself ------------------------------------------------

    public function testTheViewOnlyUserCanViewTheSettingsPageButNotEditIt(): void
    {
        $this->actAs('viewonly');

        $checker = new Checker($this->settingsPage());

        $this->assertTrue((bool) $checker->canViewPage(), 'The throwaway user must be able to view the page.');
        $this->assertFalse((bool) $checker->canEditPageProperties(), 'The throwaway user must not have edit permission.');
    }

    // ---- Saving ------------------------------------------------------------

    public function testAnAdminWithEditPermissionSavesSuccessfully(): void
    {
        $this->actAs('superuser');
        $this->assertTrue((bool) (new Checker($this->settingsPage()))->canEditPageProperties());
        $this->post($this->changeEverything(), true);
        $controller = $this->controller();

        $response = $controller->save();

        $this->assertSame('', $controller->errorText());
        $this->assertNotNull($response, 'A successful save redirects back to the page.');
        $this->assertSame('sk-dummy-NEW-VALUE-4455', $this->config->get('katalysis.ai.open_ai_key'));
        $this->assertSame('', $this->config->get('katalysis.ai.anthropic_key'));
        $this->assertSame('changed-model', $this->config->get('katalysis.ai.open_ai_model'));
        $this->assertSame('http://changed.invalid', $this->config->get('katalysis.ai.ollama_url'));
        $flashes = $this->app()->make('session')->getFlashBag()->get('page_message');
        $this->assertSame('success', $flashes[0][0] ?? null);
    }

    public function testANonSuperUserGrantedEditPagePropertiesCanSaveAndTheGrantIsFullyRemovedAfterwards(): void
    {
        $before = $this->permissionSnapshot();
        // Whatever state the page is in (inheriting, or already overriding after the seed), the test records it and
        // must leave exactly that behind.
        $start = $this->recordSettingsPageState();
        $this->assertContains($start['row']['cInheritPermissionsFrom'], ['PARENT', 'OVERRIDE']);
        // Without the grant the user can view but not save.
        $this->actAs('viewonly');
        $info = $this->viewOnlyUser();
        $this->assertFalse($info->getUserObject()->isSuperUser(), 'The user must not be the super user.');
        $this->post($this->changeEverything(), true);
        $this->assertNull($this->controller()->save());
        $this->assertSame('original-model', $this->config->get('katalysis.ai.open_ai_model'));

        // With "Edit Page Properties" on the settings page, the same user can save.
        $this->grantEditToViewOnlyUser();
        $granted = $this->permissionSnapshot();
        $this->assertNotSame($before['pageAssignments'], $granted['pageAssignments'], 'The grant should add an assignment on the settings page.');
        $this->assertNotSame($before['accessListOfAssignedAccesses'], $granted['accessListOfAssignedAccesses']);
        $this->actAs('viewonly');
        $checker = new Checker($this->settingsPage());
        $this->assertTrue((bool) $checker->canViewPage(), 'The grant must not remove view.');
        $this->assertTrue((bool) $checker->canEditPageProperties());
        $this->assertFalse((bool) $checker->canEditPagePermissions(), 'Only the one key was granted.');

        $this->post($this->changeEverything(), true);
        $controller = $this->controller();
        $response = $controller->save();

        $this->assertSame('', $controller->errorText());
        $this->assertNotNull($response, 'A successful save redirects back to the page.');
        $this->assertSame('changed-model', $this->config->get('katalysis.ai.open_ai_model'));
        $this->assertSame('sk-dummy-NEW-VALUE-4455', $this->config->get('katalysis.ai.open_ai_key'));
        $this->assertSame('', $this->config->get('katalysis.ai.anthropic_key'));
        $this->assertStringContainsString('type="submit"', $this->renderSettingsPage(), 'The form shows Save to a user who can save.');

        // Remove the grant: the user is back to view-only, and the permission rows are as they were.
        $this->removeGrantFromSettingsPage();
        $this->actAs('viewonly');
        $checker = new Checker($this->settingsPage());
        $this->assertTrue((bool) $checker->canViewPage());
        $this->assertFalse((bool) $checker->canEditPageProperties(), 'The user must lose edit permission with the grant.');
        $this->post($this->changeEverything(), true);
        $this->assertNull($this->controller()->save());

        Request::getInstance()->setCustomRequestUser(-1);
        foreach ($this->createdUsers as $created) {
            $created->delete();
        }
        $this->createdUsers = [];
        $this->forgetCachedPermissions();

        $after = $this->permissionSnapshot();
        $this->assertSame($before, $after, 'The permission rows, counts and orphan counts must be as they were before the grant.');
        $this->assertSame(0, $after['orphan accesses'] - $before['orphan accesses']);
        $this->assertSame(0, $after['unused user entities'] - $before['unused user entities']);

        // The page is in the state it was in before: the same inheritance, the same source, the same own assignments.
        $row = $this->db()->fetchAssociative(
            'SELECT cInheritPermissionsFrom, cInheritPermissionsFromCID FROM Pages WHERE cID = ?',
            [$after['settingsPageID']]
        );
        $this->assertSame($start['row'], $row, 'The page must inherit or override exactly as it did before the test.');
        $this->assertSame(
            $start['assignments'],
            $this->db()->fetchAllAssociative(
                'SELECT cID, pkID, paID FROM PagePermissionAssignments WHERE cID = ? ORDER BY pkID, paID',
                [$after['settingsPageID']]
            ),
            'The page must have the same own assignment rows as before the test.'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedUserProvider(): array
    {
        return [
            'user who can view but not edit' => ['viewonly'],
            'user who cannot view the page' => ['neuron_editor'],
            'guest' => ['guest'],
        ];
    }

    #[DataProvider('refusedUserProvider')]
    public function testAUserWithoutEditPermissionIsRefusedAndNothingChanges(string $who): void
    {
        $this->actAs($who);
        // A valid token must not help: the refusal is about permission, not the token.
        $this->post($this->changeEverything(), true);
        $before = $this->settingsSnapshot();
        $controller = $this->controller();

        $response = $controller->save();

        $this->assertNull($response, 'A refused save shows the page again; it does not redirect as if it worked.');
        $this->assertStringContainsString('permission', $controller->errorText());
        $this->assertSame($before, $this->settingsSnapshot(), 'A refused save must change nothing.');
        $this->assertSame([], $this->app()->make('session')->getFlashBag()->get('page_message'), 'No success banner.');
    }

    public function testAMissingTokenIsStillRefusedForAnAdmin(): void
    {
        $this->actAs('superuser');
        $this->post($this->changeEverything(), false);
        $before = $this->settingsSnapshot();
        $controller = $this->controller();

        $response = $controller->save();

        $this->assertNull($response);
        $this->assertMatchesRegularExpression('/token/i', $controller->errorText());
        $this->assertStringNotContainsString('permission', $controller->errorText(), 'An admin is refused for the token, not for permission.');
        $this->assertSame($before, $this->settingsSnapshot(), 'A save without a token must change nothing.');
    }

    public function testAnInvalidTokenIsStillRefusedForAnAdmin(): void
    {
        $this->actAs('superuser');
        $fields = $this->changeEverything();
        $fields['ccm_token'] = time() . ':' . str_repeat('0', 32);
        Request::getInstance()->request->replace($fields);
        $before = $this->settingsSnapshot();
        $controller = $this->controller();

        $this->assertNull($controller->save());
        $this->assertSame($before, $this->settingsSnapshot());
    }

    // ---- The form ----------------------------------------------------------

    public function testAUserWhoCanOnlyViewSeesTheStatusButNoSaveButtonAndADisabledForm(): void
    {
        $this->actAs('viewonly');

        $html = $this->renderSettingsPage();

        $this->assertSame(3, substr_count($html, 'Key set'), 'A view-only user still sees "Key set" or "Not set".');
        $this->assertStringContainsString('You can see these settings but not change them', $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*\bdisabled\b/', $html, 'The fields are disabled.');
        $this->assertStringNotContainsString('type="submit"', $html, 'Save is hidden.');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="ccm_token"/', $html, 'No token is issued to a user who cannot save.');
        foreach ([self::DUMMY_OPEN_AI, self::DUMMY_ANTHROPIC, self::DUMMY_OLLAMA] as $key) {
            $this->assertStringNotContainsString($key, $html);
            $this->assertStringNotContainsString(substr($key, -4), $html);
        }
    }

    public function testAnAdminSeesAnEnabledFormWithSave(): void
    {
        $this->actAs('superuser');

        $html = $this->renderSettingsPage();

        $this->assertStringContainsString('type="submit"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="ccm_token"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*\bdisabled\b/', $html);
        $this->assertStringNotContainsString('You can see these settings but not change them', $html);
    }
}
