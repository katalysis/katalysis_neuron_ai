<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\KatalysisPro;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use Katalysis\NeuronAi\Tests\Support\ToolBaseline;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Testing\FakeAIProvider;

/**
 * P3.7-2 (plans/P3.7-2.md): the core toolkits have no guidelines (what was true moved into tool descriptions and
 * results), and an external toolkit's guidelines are headed by its own label only: no "# FilteredToolkit", no
 * "Toolkit: X" line, no list of tool names. A real turn with FakeAIProvider; no provider call.
 */
final class PromptCutTest extends ConcreteTestCase
{
    public function testNoCoreToolkitHasGuidelines(): void
    {
        foreach (ToolBaseline::coreToolkits() as $toolkit) {
            $this->assertNull($toolkit->guidelines(), (new \ReflectionClass($toolkit))->getShortName());
        }
    }

    /** @var array<string, callable> */
    private array $savedFactories = [];

    protected function setUp(): void
    {
        $factories = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $this->savedFactories = $factories->getValue();
        $factories->setValue(null, []);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, $this->savedFactories);
        Request::getInstance()->setCustomRequestUser(-1);
    }

    public function testAnExternalToolkitsGuidelinesAreHeadedByItsLabelOnly(): void
    {
        if (!KatalysisPro::isInstalled()) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }
        // The PHPUnit boot runs no package on_start(), so katalysis_pro is registered as its controller does.
        KatalysisPro::register();
        $info = $this->app()->make(UserInfoRepository::class)->getByID(USER_SUPER_ID);
        Request::getInstance()->setCustomRequestUser($info);
        $this->app()->make('cache/request')->flush();

        $provider = new FakeAIProvider(new AssistantMessage('Done.'));
        (new ConcreteCmsAgent())
            ->setMessageStore(new InMemoryMessageStore())
            ->setThreadId('p372-prompt-' . bin2hex(random_bytes(4)))
            ->setAiProvider($provider)
            ->handleUserMessage('List the people on the site.');
        $system = (string) $provider->getRecorded()[0]->systemPrompt?->getContent();

        $this->assertStringContainsString("<TOOLS-GUIDELINES>\n# KatalysisProEntitiesToolkit\n", $system);
        $this->assertStringNotContainsString('FilteredToolkit', $system);
        $this->assertStringNotContainsString('Toolkit: ', $system);
        $this->assertStringNotContainsString("\n- list_people", $system, 'no tool-name list');
    }

    public function testAGroupAddedWithGetToolGroupBringsItsToolkitsGuidelines(): void
    {
        // Moved from ToolRoutingTest (P3.2-2), whose core toolkits no longer have guidelines.
        if (!KatalysisPro::isInstalled()) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }
        KatalysisPro::register();
        Request::getInstance()->setCustomRequestUser($this->app()->make(UserInfoRepository::class)->getByID(USER_SUPER_ID));
        $this->app()->make('cache/request')->flush();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('get_tool_group', 'call_group', ['group' => 'external'])]),
            new AssistantMessage('Done.')
        );
        (new ConcreteCmsAgent())
            ->setMessageStore(new InMemoryMessageStore())
            ->setThreadId('p372-prompt-' . bin2hex(random_bytes(4)))
            ->setAiProvider($provider)
            ->handleUserMessage('Hello');
        [$first, $second] = $provider->getRecorded();

        $this->assertStringNotContainsString('<TOOLS-GUIDELINES>', (string) $first->systemPrompt?->getContent(), 'no external tool offered, no guidelines');
        $this->assertStringContainsString('# KatalysisProEntitiesToolkit', (string) $second->systemPrompt?->getContent(), 'the group brings its guidelines');
    }
}
