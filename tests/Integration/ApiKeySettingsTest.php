<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai\NeuronAi;
use Katalysis\NeuronAi\Settings\ApiKeySettings;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\InMemoryConfig;

/**
 * P1.7a: the settings page never renders a stored API key (B-SEC-1), and saving the
 * form keeps, replaces or removes a key as described in ApiKeySettings.
 *
 * Uses an in-memory config with dummy keys, so it never reads or changes the dev
 * site's real keys, and never calls a provider.
 */
final class ApiKeySettingsTest extends ConcreteTestCase
{
    private const DUMMY_OPEN_AI = 'sk-dummy-OPENAI-0123456789-abcdefghij-ZQ9X';
    private const DUMMY_ANTHROPIC = 'sk-ant-dummy-ANTHROPIC-9876543210-mnbvcxzlkj-WQ7P';
    private const DUMMY_OLLAMA = 'dummy-ollama-secret-1122334455-RT5K';

    private Repository $config;

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

        // Saving the settings needs edit permission on the page (P1.7b), which the super user has.
        // SettingsSavePermissionTest covers the users who don't.
        $info = $this->app()->make(UserInfoRepository::class)->getByID(USER_SUPER_ID);
        $this->assertNotNull($info);
        Request::getInstance()->setCustomRequestUser($info);
    }

    protected function tearDown(): void
    {
        InMemoryConfig::reset();
        Request::getInstance()->request->replace([]);
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private function settings(): ApiKeySettings
    {
        return new ApiKeySettings($this->config);
    }

    private function storeDummyKeys(): void
    {
        $this->config->save('katalysis.ai.open_ai_key', self::DUMMY_OPEN_AI);
        $this->config->save('katalysis.ai.anthropic_key', self::DUMMY_ANTHROPIC);
        $this->config->save('katalysis.ai.ollama_key', self::DUMMY_OLLAMA);
    }

    /**
     * A settings page controller that reads and writes the in-memory config.
     */
    private function controller(): NeuronAi
    {
        $page = Page::getByPath('/dashboard/system/ai/neuron_ai');
        $this->assertFalse($page->isError(), 'The settings page is missing. Is katalysis_neuron_ai installed?');

        $controller = new class($page, $this->settings()) extends NeuronAi {
            public function __construct(Page $page, private readonly ApiKeySettings $testSettings)
            {
                parent::__construct($page);
            }

            protected function settings(): ApiKeySettings
            {
                return $this->testSettings;
            }
        };

        // What Concrete does before it calls an action: give the controller the application, then on_start().
        $controller->setApplication($this->app());
        $controller->on_start();

        return $controller;
    }

    /**
     * The settings page as the browser receives it: the real controller's view() output
     * rendered through the real view file.
     */
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

    private function post(array $fields, bool $withToken = true): void
    {
        if ($withToken) {
            $fields['ccm_token'] = $this->app()->make('token')->generate('ai.settings');
        }
        Request::getInstance()->request->replace($fields);
    }

    public function testTheRenderedSettingsPageContainsNeitherAKeyNorItsLastFourCharacters(): void
    {
        $this->storeDummyKeys();

        $html = $this->renderSettingsPage();

        foreach ([self::DUMMY_OPEN_AI, self::DUMMY_ANTHROPIC, self::DUMMY_OLLAMA] as $key) {
            $this->assertStringNotContainsString($key, $html, 'A stored key is in the page.');
            $this->assertStringNotContainsString(substr($key, -4), $html, 'The last four characters of a key are in the page.');
            $this->assertStringNotContainsString(substr($key, 0, 8), $html, 'The start of a key is in the page.');
        }
        $this->assertStringNotContainsString('sk-', $html);
    }

    public function testTheControllerNeverPassesAKeyToTheView(): void
    {
        $this->storeDummyKeys();
        $controller = $this->controller();
        $controller->view();

        $sets = $controller->getSets();

        $this->assertSame([], array_intersect(ApiKeySettings::KEY_FIELDS, array_keys($sets)), 'A key field name is passed to the view.');
        $encoded = (string) json_encode(array_filter($sets, static fn ($value): bool => is_scalar($value) || is_array($value)));
        foreach ([self::DUMMY_OPEN_AI, self::DUMMY_ANTHROPIC, self::DUMMY_OLLAMA] as $key) {
            $this->assertStringNotContainsString($key, $encoded);
        }
        $this->assertSame(['open_ai_key' => true, 'anthropic_key' => true, 'ollama_key' => true], $sets['key_status']);
    }

    public function testThePageShowsKeySetAndAnEmptyPasswordInput(): void
    {
        $this->storeDummyKeys();

        $html = $this->renderSettingsPage();

        $this->assertSame(3, substr_count($html, 'Key set'));
        foreach (ApiKeySettings::KEY_FIELDS as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="' . $field . '"[^>]*value=""/', $html);
            $this->assertStringContainsString('name="remove_' . $field . '"', $html, 'A set key needs a Remove key checkbox.');
        }
        $this->assertStringContainsString('Remove key', $html);
    }

    public function testAKeyThatIsNotSetShowsNotSetAndNoRemoveCheckbox(): void
    {
        $this->config->save('katalysis.ai.open_ai_key', self::DUMMY_OPEN_AI);

        $html = $this->renderSettingsPage();

        $this->assertSame(1, substr_count($html, 'Key set'));
        $this->assertStringContainsString('name="remove_open_ai_key"', $html);
        $this->assertStringNotContainsString('name="remove_anthropic_key"', $html);
        $this->assertStringContainsString('Not set', $html);
    }

    public function testTheFormKeepsConcretesCsrfToken(): void
    {
        $html = $this->renderSettingsPage();

        $this->assertMatchesRegularExpression('/<input[^>]*name="ccm_token"/', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*method="post"/', $html);
    }

    public function testASaveWithoutAValidTokenChangesNothing(): void
    {
        $this->storeDummyKeys();
        $this->post(['open_ai_key' => 'sk-dummy-SHOULD-NOT-SAVE-0000', 'open_ai_model' => 'x'], false);

        $this->controller()->save();

        $this->assertSame(self::DUMMY_OPEN_AI, $this->config->get('katalysis.ai.open_ai_key'));
        $this->assertNotSame('x', $this->config->get('katalysis.ai.open_ai_model'));
    }

    public function testAnOllamaUrlWithCredentialsRefusesTheWholeSave(): void
    {
        // P3.9-4: the URL is shown in plain text, so a password in it is refused, and nothing else is saved either.
        $this->storeDummyKeys();
        $this->config->save('katalysis.ai.ollama_url', 'http://localhost:11434');
        $this->post(['ollama_url' => 'http://admin:s3cret-pass@ollama.example', 'open_ai_model' => 'gpt-4o-mini', 'open_ai_key' => 'sk-dummy-NOT-SAVED-0000']);

        $controller = $this->controller();
        $controller->save();

        $this->assertSame('http://localhost:11434', $this->config->get('katalysis.ai.ollama_url'));
        $this->assertNotSame('gpt-4o-mini', $this->config->get('katalysis.ai.open_ai_model'));
        $this->assertSame(self::DUMMY_OPEN_AI, $this->config->get('katalysis.ai.open_ai_key'));
        $errors = implode(' ', array_map('strval', (new \ReflectionProperty($controller, 'error'))->getValue($controller)->getList()));
        $this->assertStringContainsString('Ollama Key', $errors);
        $this->assertStringNotContainsString('s3cret-pass', $errors);
    }

    public function testABlankSaveKeepsEveryKey(): void
    {
        $this->storeDummyKeys();
        $this->post(['open_ai_key' => '', 'anthropic_key' => '   ', 'ollama_key' => '', 'open_ai_model' => 'gpt-4o']);

        $this->controller()->save();

        $this->assertSame(self::DUMMY_OPEN_AI, $this->config->get('katalysis.ai.open_ai_key'));
        $this->assertSame(self::DUMMY_ANTHROPIC, $this->config->get('katalysis.ai.anthropic_key'));
        $this->assertSame(self::DUMMY_OLLAMA, $this->config->get('katalysis.ai.ollama_key'));
        $this->assertSame('gpt-4o', $this->config->get('katalysis.ai.open_ai_model'), 'Other fields still save.');
    }

    public function testAKeyFieldMissingFromThePostKeepsTheKey(): void
    {
        $this->storeDummyKeys();

        $this->settings()->save(['open_ai_model' => 'gpt-4o']);

        $this->assertSame(self::DUMMY_OPEN_AI, $this->config->get('katalysis.ai.open_ai_key'));
    }

    public function testANewValueReplacesOnlyThatKey(): void
    {
        $this->storeDummyKeys();
        $this->post(['open_ai_key' => '  sk-dummy-NEW-VALUE-4455  ', 'anthropic_key' => '']);

        $this->controller()->save();

        $this->assertSame('sk-dummy-NEW-VALUE-4455', $this->config->get('katalysis.ai.open_ai_key'));
        $this->assertSame(self::DUMMY_ANTHROPIC, $this->config->get('katalysis.ai.anthropic_key'));
        $this->assertSame(self::DUMMY_OLLAMA, $this->config->get('katalysis.ai.ollama_key'));
    }

    public function testTheRemoveCheckboxClearsOnlyThatKey(): void
    {
        $this->storeDummyKeys();
        $this->post(['open_ai_key' => '', 'remove_anthropic_key' => '1']);

        $this->controller()->save();

        $this->assertSame(self::DUMMY_OPEN_AI, $this->config->get('katalysis.ai.open_ai_key'));
        $this->assertSame('', $this->config->get('katalysis.ai.anthropic_key'));
        $this->assertSame(self::DUMMY_OLLAMA, $this->config->get('katalysis.ai.ollama_key'));
        $this->assertFalse($this->settings()->keyStatus()['anthropic_key']);
    }

    public function testRemoveWinsWhenANewValueIsPostedWithIt(): void
    {
        $this->storeDummyKeys();

        $removedWithNewValue = $this->settings()->save([
            'open_ai_key' => 'sk-dummy-CONFLICT-7788',
            'remove_open_ai_key' => '1',
            'anthropic_key' => 'sk-ant-dummy-NEW-1234',
        ]);

        $this->assertSame('', $this->config->get('katalysis.ai.open_ai_key'), 'Remove wins over a new value.');
        $this->assertSame(['open_ai_key'], $removedWithNewValue, 'Only the conflicting key is reported.');
        $this->assertSame('sk-ant-dummy-NEW-1234', $this->config->get('katalysis.ai.anthropic_key'));
    }

    public function testRemoveOnItsOwnIsNotReportedAsAConflict(): void
    {
        $this->storeDummyKeys();

        $this->assertSame([], $this->settings()->save(['open_ai_key' => '', 'remove_open_ai_key' => '1']));
    }

    public function testTheBannerSaysSoWhenANewValueWasNotSavedBecauseRemoveWasTicked(): void
    {
        $this->storeDummyKeys();
        $flashBag = $this->app()->make('session')->getFlashBag();
        $flashBag->clear();
        $this->post(['open_ai_key' => 'sk-dummy-CONFLICT-7788', 'remove_open_ai_key' => '1']);

        $this->controller()->save();

        $flashes = $flashBag->get('page_message');
        $this->assertCount(1, $flashes, 'One banner: the dashboard shows only one.');
        $this->assertSame('message', $flashes[0][0]);
        $this->assertStringContainsString('Key removed; the new value was not saved.', $flashes[0][1]);
        $this->assertStringNotContainsString('sk-dummy-CONFLICT-7788', $flashes[0][1], 'The banner never echoes a key.');
        $this->assertSame('', $this->config->get('katalysis.ai.open_ai_key'));
    }

    public function testAPlainSaveStillShowsTheSuccessBanner(): void
    {
        $flashBag = $this->app()->make('session')->getFlashBag();
        $flashBag->clear();
        $this->post(['open_ai_model' => 'gpt-4o']);

        $this->controller()->save();

        $flashes = $flashBag->get('page_message');
        $this->assertSame('success', $flashes[0][0] ?? null);
    }

    public function testTheNonSecretFieldsAreEscapedInThePage(): void
    {
        $this->config->save('katalysis.ai.open_ai_model', '"><script>alert(1)</script>');
        $this->config->save('katalysis.ai.ollama_url', "x' onfocus='alert(2)");

        $html = $this->renderSettingsPage();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString("onfocus='alert(2)", $html);
    }

    public function testNonStringPostValuesAreIgnored(): void
    {
        $this->storeDummyKeys();

        $this->settings()->save(['open_ai_key' => ['x'], 'remove_open_ai_key' => ['1'], 'anthropic_key' => ['y']]);

        $this->assertSame(self::DUMMY_OPEN_AI, $this->config->get('katalysis.ai.open_ai_key'));
        $this->assertSame(self::DUMMY_ANTHROPIC, $this->config->get('katalysis.ai.anthropic_key'));
    }

    public function testKeyStatusReportsOnlyWhetherAKeyIsSet(): void
    {
        $this->assertSame(['open_ai_key' => false, 'anthropic_key' => false, 'ollama_key' => false], $this->settings()->keyStatus());

        $this->config->save('katalysis.ai.open_ai_key', self::DUMMY_OPEN_AI);

        $this->assertSame(['open_ai_key' => true, 'anthropic_key' => false, 'ollama_key' => false], $this->settings()->keyStatus());
        $this->assertStringNotContainsString(self::DUMMY_OPEN_AI, (string) json_encode($this->settings()->keyStatus()));
        $this->assertStringNotContainsString(self::DUMMY_OPEN_AI, (string) json_encode($this->settings()->plainValues()));
    }
}
