<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Config\Repository\Repository;
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
        foreach ($this->createdUsers as $info) {
            $info->delete();
        }
        $this->createdUsers = [];
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
