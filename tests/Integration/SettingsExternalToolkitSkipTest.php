<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai\NeuronAi;
use Katalysis\NeuronAi\Settings\ApiKeySettings;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\InMemoryConfig;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * P2.2 (P2.1 findings 5.4): an external toolkit that can't be built is shown on the settings page, so a silent loss of
 * a package's tools is noticed. Only to users who can configure the assistant.
 *
 * Settings live in an in-memory config with no keys; the registry is replaced for the test and put back after.
 */
final class SettingsExternalToolkitSkipTest extends ConcreteTestCase
{
    private const SETTINGS_PATH = '/dashboard/system/ai/neuron_ai';

    /** @var array<string, callable> */
    private array $savedFactories = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Concrete's router finds package controllers by path; here the controller is loaded directly.
        require_once dirname(__DIR__, 2) . '/controllers/single_page/dashboard/system/ai/neuron_ai.php';
    }

    protected function setUp(): void
    {
        InMemoryConfig::reset();
        $property = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $this->savedFactories = $property->getValue();
        $property->setValue(null, []);
        ExternalToolkitRegistry::registerToolkitFactory('p22_broken_package', static fn (): array => [new class () extends AbstractToolkit {
            public function provide(): array
            {
                throw new \Error('Cannot instantiate abstract class NeuronAI\Tools\Tool');
            }
        }]);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, $this->savedFactories);
        Request::getInstance()->setCustomRequestUser(-1);
        InMemoryConfig::reset();
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
        // Permission answers are cached for the request.
        $this->app()->make('cache/request')->flush();
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} what the controller passed to the view, and the rendered page
     */
    private function renderSettingsPage(): array
    {
        $page = Page::getByPath(self::SETTINGS_PATH);
        $this->assertFalse($page->isError(), 'The settings page is missing: is katalysis_neuron_ai installed?');
        $controller = new class($page, new ApiKeySettings(InMemoryConfig::repository())) extends NeuronAi {
            public function __construct(Page $page, private readonly ApiKeySettings $testSettings)
            {
                parent::__construct($page);
            }

            protected function settings(): ApiKeySettings
            {
                return $this->testSettings;
            }
        };
        $controller->setApplication($this->app());
        $controller->on_start();
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

        return [$controller->getSets(), $render($vars, $viewFile)];
    }

    public function testAnAdminSeesTheSkippedToolkit(): void
    {
        $this->actAs('superuser');

        [$sets, $html] = $this->renderSettingsPage();

        $this->assertCount(1, $sets['external_toolkit_skips']);
        $this->assertSame('p22_broken_package', $sets['external_toolkit_skips'][0]['provider']);
        $this->assertStringContainsString('id="neuron-external-toolkit-skips"', $html);
        $this->assertStringContainsString('p22_broken_package', $html);
        $this->assertStringContainsString('Cannot instantiate abstract class', $html);
    }

    public function testNoWarningWhenEveryToolkitBuilds(): void
    {
        (new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, []);
        $this->actAs('superuser');

        [$sets, $html] = $this->renderSettingsPage();

        $this->assertSame([], $sets['external_toolkit_skips']);
        $this->assertStringNotContainsString('neuron-external-toolkit-skips', $html);
    }

    public function testAUserWhoCannotConfigureSeesNoWarning(): void
    {
        $this->actAs('neuron_editor');

        [$sets, $html] = $this->renderSettingsPage();

        $this->assertArrayNotHasKey('external_toolkit_skips', $sets);
        $this->assertStringNotContainsString('neuron-external-toolkit-skips', $html);
        $this->assertStringNotContainsString('p22_broken_package', $html);
    }
}
