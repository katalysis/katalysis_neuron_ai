<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai\NeuronAi;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Access\NeuronPermissions;
use Katalysis\NeuronAi\Settings\ApiKeySettings;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\InMemoryConfig;

/**
 * P1.10 / P1.10a: the browser test user `neuron_chat_editor` (seed_test_content.php).
 *
 * It has `neuron_editor`'s page rights on /neuron-test, plus the Concrete task permission "Use Neuron AI assistant"
 * through the seeded group "Neuron Test Editors", so the chat panel is available to it. `neuron_editor` is in no group
 * and stays the "no panel access" fixture. These tests check that:
 *
 *  - the panel access check passes for one and fails for the other;
 *  - the two have the same page permissions on /neuron-test/headings (and the same "no" for delete, move and
 *    permissions), so the browser user behaves like the scenario user;
 *  - the user has no dashboard access (the settings page is back to inheriting from /dashboard, with no override of its
 *    own), and the settings page, if it were rendered for them, shows only a message: no key, no last four, no form;
 *  - they can't save the settings: they don't hold "Configure Neuron AI", so `save()` refuses and changes nothing.
 *
 * Nothing calls the AI provider. The settings use an in-memory config with dummy keys, so the dev site's real
 * keys are never read or changed. Needs the seeded, upgraded dev site.
 */
final class ChatEditorAccessTest extends ConcreteTestCase
{
    private const CHAT_USER = 'neuron_chat_editor';
    private const NO_ACCESS_USER = 'neuron_editor';
    private const HEADINGS_PATH = '/neuron-test/headings';
    private const SETTINGS_PATH = '/dashboard/system/ai/neuron_ai';

    private const DUMMY_OPEN_AI = 'sk-dummy-OPENAI-0123456789-abcdefghij-ZQ9X';
    private const DUMMY_ANTHROPIC = 'sk-ant-dummy-ANTHROPIC-9876543210-mnbvcxzlkj-WQ7P';
    private const DUMMY_OLLAMA = 'dummy-ollama-secret-1122334455-RT5K';

    /** Page permissions the seed grants on /neuron-test (the Checker method for each key). */
    private const GRANTED = [
        'canViewPage',
        'canViewPageVersions',
        'canEditPageContents',
        'canEditPageProperties',
        'canAddSubpage',
        'canApprovePageVersions',
    ];

    /** Page permissions neither fixture user has. */
    private const NOT_GRANTED = [
        'canDeletePage',
        'canMoveOrCopyPage',
        'canEditPagePermissions',
        'canEditPageSpeedSettings',
        'canEditPageTemplate',
    ];

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
        $this->config->save('katalysis.ai.open_ai_key', self::DUMMY_OPEN_AI);
        $this->config->save('katalysis.ai.anthropic_key', self::DUMMY_ANTHROPIC);
        $this->config->save('katalysis.ai.ollama_key', self::DUMMY_OLLAMA);
        $this->config->save('katalysis.ai.open_ai_model', 'original-model');
        $this->config->save('katalysis.ai.ollama_url', 'http://original.invalid');
        $this->app()->make('session')->getFlashBag()->clear();
    }

    protected function tearDown(): void
    {
        $request = Request::getInstance();
        $request->request->replace([]);
        $request->headers->remove(ChatAccess::TOKEN_HEADER);
        $request->setCustomRequestUser(-1);
        InMemoryConfig::reset();
    }

    // ---- Helpers -----------------------------------------------------------

    private function actAs(string $who): void
    {
        $info = $this->app()->make(UserInfoRepository::class)->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
        // Permission answers are cached for the request.
        $this->app()->make('cache/request')->flush();
    }

    private function page(string $path): Page
    {
        $page = Page::getByPath($path);
        $this->assertFalse($page->isError(), "The page {$path} is missing. Run the seed script first.");

        return $page;
    }

    private function settingsPage(): Page
    {
        return $this->page(self::SETTINGS_PATH);
    }

    /**
     * @return array<string, bool> answer of each page permission, keyed by the Checker method
     */
    private function pagePermissions(string $who, string $path): array
    {
        $this->actAs($who);
        $checker = new Checker($this->page($path));
        $answers = [];
        foreach (array_merge(self::GRANTED, self::NOT_GRANTED) as $method) {
            $answers[$method] = (bool) $checker->$method();
        }

        return $answers;
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

    /**
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

    // ---- Panel access ------------------------------------------------------

    public function testTheChatUserCanUseTheAssistantAndTheNoAccessUserCannot(): void
    {
        $this->actAs(self::CHAT_USER);
        $this->assertTrue(ChatAccess::canUse(), self::CHAT_USER . ' must pass the panel access check.');
        $token = ChatAccess::generateToken();
        $this->assertNull(ChatAccess::refusalReason(true, $token), 'A valid token is accepted for a state-changing request.');
        $this->assertNull(ChatAccess::refusalReason(false, null), 'A GET needs no token.');

        $this->actAs(self::NO_ACCESS_USER);
        $this->assertFalse(ChatAccess::canUse(), self::NO_ACCESS_USER . ' must not pass the panel access check.');
        $this->assertSame(
            ChatAccess::ACCESS_DENIED_ERROR,
            ChatAccess::refusalReason(true, ChatAccess::generateToken()),
            'A user without access is refused even with a valid token.'
        );
    }

    // ---- Page permissions --------------------------------------------------

    public function testTheChatUserHasTheSamePagePermissionsAsTheEditorOnHeadings(): void
    {
        $chat = $this->pagePermissions(self::CHAT_USER, self::HEADINGS_PATH);
        $editor = $this->pagePermissions(self::NO_ACCESS_USER, self::HEADINGS_PATH);

        $this->assertSame($editor, $chat, 'The two users must get the same answer for every page permission checked.');
        foreach (self::GRANTED as $method) {
            $this->assertTrue($chat[$method], "{$method} must be granted.");
        }
        foreach (self::NOT_GRANTED as $method) {
            $this->assertFalse($chat[$method], "{$method} must not be granted (delete, move and permissions stay with a person).");
        }
    }

    public function testTheChatUserHasTheSameAddBlockRightOnHeadingsMainAsTheEditor(): void
    {
        $answers = [];
        foreach ([self::CHAT_USER, self::NO_ACCESS_USER] as $who) {
            $this->actAs($who);
            $page = $this->page(self::HEADINGS_PATH);
            $area = Area::get($page, 'Main');
            $this->assertNotNull($area, 'The Main area on Headings is missing.');
            $checker = new Checker($area);
            $answers[$who] = [
                'html' => (bool) $checker->canAddBlock(BlockType::getByHandle('html')),
                'content' => (bool) $checker->canAddBlock(BlockType::getByHandle('content')),
            ];
        }

        $this->assertSame($answers[self::NO_ACCESS_USER], $answers[self::CHAT_USER]);
        $this->assertTrue($answers[self::CHAT_USER]['html'], 'Scenario 07 adds an html block to this area.');
    }

    public function testTheChatUserHasTheSameRightsOnEveryNeuronTestPageAsTheEditor(): void
    {
        foreach (['/neuron-test', '/neuron-test/columns', '/neuron-test/injection', '/neuron-test/people', '/neuron-test/move-me', '/neuron-test/move-target'] as $path) {
            $this->assertSame(
                $this->pagePermissions(self::NO_ACCESS_USER, $path),
                $this->pagePermissions(self::CHAT_USER, $path),
                "Page permissions differ on {$path}."
            );
        }
    }

    // ---- The settings page: no dashboard access, no configure key ----------

    public function testTheSettingsPageInheritsItsPermissionsFromTheDashboardAgain(): void
    {
        $page = $this->settingsPage();
        $row = $this->app()->make(\Concrete\Core\Database\Connection\Connection::class)->fetchAssociative(
            'SELECT cInheritPermissionsFrom FROM Pages WHERE cID = ?',
            [$page->getCollectionID()]
        );

        $this->assertSame('PARENT', $row['cInheritPermissionsFrom'], 'The settings page must not override the dashboard permissions (P1.10a removed the seed grant).');
    }

    public function testTheChatUserHasNoDashboardAccessAndNoConfigureKey(): void
    {
        $this->actAs(self::CHAT_USER);

        foreach (['/dashboard', '/dashboard/system', '/dashboard/system/ai', self::SETTINGS_PATH, '/dashboard/sitemap'] as $path) {
            $page = Page::getByPath($path);
            if ($page->isError()) {
                continue;
            }
            $this->assertFalse((bool) (new Checker($page))->canViewPage(), "{$path} must not be viewable by " . self::CHAT_USER . '.');
        }
        $this->assertFalse(NeuronPermissions::userHas(NeuronPermissions::CONFIGURE_HANDLE), 'The use key does not include configure.');
    }

    public function testTheSettingsPageShowsTheChatUserOnlyAMessage(): void
    {
        $this->actAs(self::CHAT_USER);

        $html = $this->renderSettingsPage();

        $this->assertStringContainsString("You don't have permission to change Neuron AI settings.", \html_entity_decode($html, ENT_QUOTES));
        $this->assertStringNotContainsString('Key set', $html, 'No key status is shown.');
        $this->assertStringNotContainsString('<form', $html, 'There is no form.');
        $this->assertStringNotContainsString('type="submit"', $html);
        $this->assertStringNotContainsString('ccm_token', $html, 'No settings token is issued.');
        $this->assertStringNotContainsString('original-model', $html, 'No setting values are shown either.');
        foreach ([self::DUMMY_OPEN_AI, self::DUMMY_ANTHROPIC, self::DUMMY_OLLAMA] as $key) {
            $this->assertStringNotContainsString($key, $html, 'A stored key is rendered.');
            $this->assertStringNotContainsString(substr($key, -4), $html, 'The last four characters of a stored key are rendered.');
        }
    }

    // ---- The settings page: cannot save ------------------------------------

    public function testTheChatUserCannotSaveTheSettings(): void
    {
        $this->actAs(self::CHAT_USER);

        // A valid token must not help: the refusal is about permission.
        Request::getInstance()->request->replace($this->changeEverything() + [
            'ccm_token' => $this->app()->make('token')->generate('ai.settings'),
        ]);
        $before = (array) $this->config->get('katalysis.ai');
        $controller = $this->controller();

        $response = $controller->save();

        $this->assertNull($response, 'A refused save shows the page again; it does not redirect as if it worked.');
        $this->assertStringContainsString('permission', $controller->errorText());
        $this->assertSame($before, (array) $this->config->get('katalysis.ai'), 'Nothing may change.');
        $this->assertSame([], $this->app()->make('session')->getFlashBag()->get('page_message'), 'No success banner.');
    }
}
