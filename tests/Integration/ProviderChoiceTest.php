<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai\NeuronAi;
use Katalysis\NeuronAi\Agent\RoutedAnthropic;
use Katalysis\NeuronAi\Agent\RoutedOllama;
use Katalysis\NeuronAi\Agent\RoutedOpenAI;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Settings\ApiKeySettings;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\InMemoryConfig;
use Katalysis\NeuronAi\Tools\Support\CallableTool;

/**
 * P3.8-1: the agent builds the provider and model the settings name (OpenAI and gpt-4o when none is set), every one
 * accepting a call to a tool of the registry the request didn't offer (P3.2-2), and the settings page refuses a model
 * it doesn't know, or a provider with no key, keeping the old values. Providers are built, never called.
 */
final class ProviderChoiceTest extends ConcreteTestCase
{
    private const KEYS = ['katalysis.ai.provider', 'katalysis.ai.open_ai_model', 'katalysis.ai.anthropic_model', 'katalysis.ai.ollama_url', 'katalysis.ai.ollama_model'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 2) . '/controllers/single_page/dashboard/system/ai/neuron_ai.php';
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            Config::set($key, null);
        }
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    private static function provider(): object
    {
        return (new \ReflectionMethod(ConcreteCmsAgent::class, 'provider'))->invoke(new ConcreteCmsAgent());
    }

    private static function model(object $provider): string
    {
        return (string) (new \ReflectionProperty($provider, 'model'))->getValue($provider);
    }

    public function testWithNothingSetItIsOpenAiAndGpt4o(): void
    {
        Config::set('katalysis.ai.provider', '');
        Config::set('katalysis.ai.open_ai_model', '');
        $provider = self::provider();

        $this->assertInstanceOf(RoutedOpenAI::class, $provider);
        $this->assertSame('gpt-4o', self::model($provider));
    }

    public function testTheSettingsChooseAnthropicOrOllama(): void
    {
        Config::set('katalysis.ai.provider', 'anthropic');
        Config::set('katalysis.ai.anthropic_model', 'claude-sonnet-5-5');
        $anthropic = self::provider();
        $this->assertInstanceOf(RoutedAnthropic::class, $anthropic);
        $this->assertSame('claude-sonnet-5-5', self::model($anthropic));

        Config::set('katalysis.ai.provider', 'ollama');
        Config::set('katalysis.ai.ollama_url', 'http://localhost:11434/api');
        Config::set('katalysis.ai.ollama_model', 'llama3.1:8b');
        $ollama = self::provider();
        $this->assertInstanceOf(RoutedOllama::class, $ollama);
        $this->assertSame('llama3.1:8b', self::model($ollama));
    }

    public function testEveryProviderAcceptsACallToARegistryToolNotOffered(): void
    {
        $offered = new CallableTool('get_page', 'offered');
        $notOffered = new CallableTool('list_topic_trees', 'in the registry only');
        foreach ([
            new RoutedOpenAI(key: 'x', model: 'gpt-4o'),
            new RoutedAnthropic(key: 'x', model: 'claude-sonnet-5-5'),
            new RoutedOllama(url: 'http://localhost:11434/api', model: 'llama3.1:8b'),
        ] as $provider) {
            $provider->setTools([$offered])->setRegistry([$offered, $notOffered]);
            $this->assertSame('list_topic_trees', $provider->findTool('list_topic_trees')->getName(), $provider::class);
        }
    }

    public function testTheSettingsPageRefusesAnUnknownModelAndAProviderWithoutAKey(): void
    {
        InMemoryConfig::reset();
        $config = InMemoryConfig::repository();
        $config->save('katalysis.ai.open_ai_model', 'gpt-4o');
        $config->save('katalysis.ai.provider', 'openai');
        Request::getInstance()->setCustomRequestUser($this->app()->make(UserInfoRepository::class)->getByID(USER_SUPER_ID));

        $this->save($config, ['open_ai_model' => 'gpt-40']);
        $this->assertSame('gpt-4o', $config->get('katalysis.ai.open_ai_model'), 'P2.8\'s typo is not saved');

        $errors = $this->save($config, ['provider' => 'anthropic', 'anthropic_model' => 'claude-sonnet-5-5']);
        $this->assertSame('openai', $config->get('katalysis.ai.provider'), 'no Anthropic key, so Anthropic is not chosen');
        $this->assertStringContainsString('Anthropic', $errors);
    }

    /**
     * Posts the form to the settings page controller, with its token, against the in-memory config.
     *
     * @param array<string, string> $fields
     */
    private function save(\Concrete\Core\Config\Repository\Repository $config, array $fields): string
    {
        $fields['ccm_token'] = $this->app()->make('token')->generate('ai.settings');
        Request::getInstance()->request->replace($fields);

        $controller = new class(Page::getByPath('/dashboard/system/ai/neuron_ai'), new ApiKeySettings($config)) extends NeuronAi {
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
        $controller->save();

        return implode(' ', array_map('strval', (new \ReflectionProperty($controller, 'error'))->getValue($controller)->getList()));
    }
}
