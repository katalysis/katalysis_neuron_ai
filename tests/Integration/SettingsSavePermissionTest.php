<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai\NeuronAi;
use Katalysis\NeuronAi\Access\NeuronPermissions;
use Katalysis\NeuronAi\Settings\ApiKeySettings;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\InMemoryConfig;
use Katalysis\NeuronAi\Tests\Support\TaskKeyFixture;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1.7b, reworked in P1.10a: saving the settings needs the Concrete task permission "Configure Neuron AI", as well
 * as the CSRF token. Being able to use the assistant ("Use Neuron AI assistant") is not enough.
 *
 * The users are throwaway users in throwaway groups that hold one key each (TaskKeyFixture). The fixture puts each
 * key's assignment back exactly as it found it and deletes everything it created. No page permission is touched:
 * the settings page keeps inheriting from /dashboard.
 *
 * Settings live in an in-memory config with dummy keys, so the dev site's real keys are never read or changed, and
 * nothing calls a provider.
 */
final class SettingsSavePermissionTest extends ConcreteTestCase
{
    private const DUMMY_OPEN_AI = 'sk-dummy-OPENAI-0123456789-abcdefghij-ZQ9X';
    private const DUMMY_ANTHROPIC = 'sk-ant-dummy-ANTHROPIC-9876543210-mnbvcxzlkj-WQ7P';
    private const DUMMY_OLLAMA = 'dummy-ollama-secret-1122334455-RT5K';
    private const SETTINGS_PATH = '/dashboard/system/ai/neuron_ai';

    private Repository $config;
    private TaskKeyFixture $fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Concrete's router finds package controllers by path; here the controller is loaded directly.
        require_once dirname(__DIR__, 2) . '/controllers/single_page/dashboard/system/ai/neuron_ai.php';
    }

    protected function setUp(): void
    {
        $this->fixture = new TaskKeyFixture($this->app());
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
        $this->fixture->restore();
        InMemoryConfig::reset();
    }

    // ---- Helpers -----------------------------------------------------------

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * A throwaway user in a throwaway group that holds the given keys.
     *
     * @param string[] $handles
     */
    private function userHolding(array $handles): UserInfo
    {
        $info = $this->fixture->createUser('holder');
        $group = $this->fixture->createGroup();
        $this->fixture->addToGroup($info, $group);
        foreach ($handles as $handle) {
            $this->fixture->grantToGroup($handle, $group);
        }

        return $info;
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
        // Permission answers are cached for the request.
        $this->fixture->flush();
    }

    private function settingsPage(): Page
    {
        $page = Page::getByPath(self::SETTINGS_PATH);
        $this->assertFalse($page->isError(), 'The settings page is missing: is katalysis_neuron_ai installed?');

        return $page;
    }

    /**
     * Every row that says who holds the two keys, in a form that can be compared before and after a test.
     *
     * @return array<string, mixed>
     */
    private function keyRowsSnapshot(): array
    {
        $db = $this->db();
        $handles = [NeuronPermissions::USE_HANDLE, NeuronPermissions::CONFIGURE_HANDLE];

        return [
            'assignments' => $db->fetchAllAssociative(
                'SELECT ta.pkID, ta.paID FROM PermissionAssignments ta JOIN PermissionKeys pk ON pk.pkID = ta.pkID'
                . ' WHERE pk.pkHandle IN (?, ?) ORDER BY ta.pkID',
                $handles
            ),
            'lists' => $db->fetchAllAssociative(
                'SELECT pal.paID, pal.peID, pal.accessType FROM PermissionAccessList pal'
                . ' WHERE pal.paID IN (SELECT ta.paID FROM PermissionAssignments ta JOIN PermissionKeys pk ON pk.pkID = ta.pkID'
                . ' WHERE pk.pkHandle IN (?, ?)) ORDER BY pal.paID, pal.peID',
                $handles
            ),
            'accesses' => (int) $db->fetchOne('SELECT COUNT(*) FROM PermissionAccess'),
            'list rows' => (int) $db->fetchOne('SELECT COUNT(*) FROM PermissionAccessList'),
            'group entities' => (int) $db->fetchOne('SELECT COUNT(*) FROM PermissionAccessEntityGroups'),
            'entities' => (int) $db->fetchOne('SELECT COUNT(*) FROM PermissionAccessEntities'),
            'groups' => (int) $db->fetchOne('SELECT COUNT(*) FROM `Groups`'),
            'users' => (int) $db->fetchOne('SELECT COUNT(*) FROM Users'),
        ];
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
            'open_ai_model' => 'gpt-4o-mini',
            'anthropic_model' => 'claude-haiku-5-5',
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

    // ---- Saving ------------------------------------------------------------

    public function testTheSuperUserSavesSuccessfully(): void
    {
        $this->actAs('superuser');
        $this->post($this->changeEverything(), true);
        $controller = $this->controller();

        $response = $controller->save();

        $this->assertSame('', $controller->errorText());
        $this->assertNotNull($response, 'A successful save redirects back to the page.');
        $this->assertSame('sk-dummy-NEW-VALUE-4455', $this->config->get('katalysis.ai.open_ai_key'));
        $this->assertSame('', $this->config->get('katalysis.ai.anthropic_key'));
        $this->assertSame('gpt-4o-mini', $this->config->get('katalysis.ai.open_ai_model'));
        $this->assertSame('http://changed.invalid', $this->config->get('katalysis.ai.ollama_url'));
        $flashes = $this->app()->make('session')->getFlashBag()->get('page_message');
        $this->assertSame('success', $flashes[0][0] ?? null);
    }

    public function testANonSuperUserWhoHoldsTheConfigureKeyCanSaveAndTheGrantIsFullyRemovedAfterwards(): void
    {
        $before = $this->keyRowsSnapshot();

        $configurer = $this->userHolding([NeuronPermissions::CONFIGURE_HANDLE]);
        $this->assertNotSame($before, $this->keyRowsSnapshot(), 'The grant should change the key rows.');
        $this->actAs($configurer);
        $this->assertFalse($configurer->getUserObject()->isSuperUser(), 'The user must not be the super user.');
        $this->post($this->changeEverything(), true);
        $controller = $this->controller();

        $response = $controller->save();

        $this->assertSame('', $controller->errorText());
        $this->assertNotNull($response, 'A successful save redirects back to the page.');
        $this->assertSame('gpt-4o-mini', $this->config->get('katalysis.ai.open_ai_model'));
        $this->assertSame('sk-dummy-NEW-VALUE-4455', $this->config->get('katalysis.ai.open_ai_key'));
        $this->assertSame('', $this->config->get('katalysis.ai.anthropic_key'));
        $this->assertStringContainsString('type="submit"', $this->renderSettingsPage(), 'The form shows Save to a user who can save.');

        // Take everything back: the key rows, accesses, groups and users are as they were.
        Request::getInstance()->setCustomRequestUser(-1);
        $this->fixture->restore();
        $this->assertSame($before, $this->keyRowsSnapshot(), 'The test must leave the permission rows exactly as it found them.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedUserProvider(): array
    {
        return [
            'user who can use the assistant but not configure it' => ['useonly'],
            'user with neither key' => ['neuron_editor'],
            'guest' => ['guest'],
        ];
    }

    #[DataProvider('refusedUserProvider')]
    public function testAUserWithoutTheConfigureKeyIsRefusedAndNothingChanges(string $who): void
    {
        $this->actAs($who === 'useonly' ? $this->userHolding([NeuronPermissions::USE_HANDLE]) : $who);
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

    // ---- The page ----------------------------------------------------------

    public function testAUserWhoCanOnlyUseTheAssistantSeesOnlyAMessage(): void
    {
        $this->actAs($this->userHolding([NeuronPermissions::USE_HANDLE]));

        $html = $this->renderSettingsPage();

        $this->assertStringContainsString("You don't have permission to change Neuron AI settings.", \html_entity_decode($html, ENT_QUOTES));
        $this->assertStringNotContainsString('Key set', $html, 'No key status is shown.');
        $this->assertStringNotContainsString('Not set', $html);
        $this->assertStringNotContainsString('<form', $html, 'There is no form.');
        $this->assertStringNotContainsString('type="submit"', $html, 'Save is hidden.');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="ccm_token"/', $html, 'No token is issued to a user who cannot save.');
        foreach ([self::DUMMY_OPEN_AI, self::DUMMY_ANTHROPIC, self::DUMMY_OLLAMA] as $key) {
            $this->assertStringNotContainsString($key, $html);
            $this->assertStringNotContainsString(substr($key, -4), $html);
        }
    }

    public function testAnAdminSeesTheFormWithSaveAndOnlyWhetherEachKeyIsSet(): void
    {
        $this->actAs('superuser');

        $html = $this->renderSettingsPage();

        $this->assertSame(3, substr_count($html, 'Key set'), 'The page says whether each key is set.');
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="ccm_token"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*\bdisabled\b/', $html);
        $this->assertStringNotContainsString("You don't have permission", \html_entity_decode($html, ENT_QUOTES));
        foreach ([self::DUMMY_OPEN_AI, self::DUMMY_ANTHROPIC, self::DUMMY_OLLAMA] as $key) {
            $this->assertStringNotContainsString($key, $html, 'A stored key is rendered.');
            $this->assertStringNotContainsString(substr($key, -4), $html, 'The last four characters of a stored key are rendered.');
        }
    }
}
