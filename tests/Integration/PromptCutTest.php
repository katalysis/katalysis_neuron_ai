<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ToolBaseline;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
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

    public function testAnExternalToolkitsGuidelinesAreHeadedByItsLabelOnly(): void
    {
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
}
