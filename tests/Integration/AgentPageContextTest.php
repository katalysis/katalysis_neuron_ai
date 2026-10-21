<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\PageContextTag;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\PromptPreview;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;

/**
 * P1.9: the page the user is on reaches the agent's instructions afresh on every turn, every user message carries the
 * tag for its own page, and a restored chat history does not carry an old page context with it (B-CTX-1).
 *
 * On Neuron 4 (P2.4) the prompt is built for every turn, so there is no cache to drop. These tests check that through
 * what the provider is actually given: real turns of the agent, with Neuron's FakeAIProvider as the model and v4's
 * in-memory history. No provider call, no API key, nothing written. P2.5 points test 4 at the Chat row store and adds
 * the check that the stored row holds no instructions.
 *
 * Needs the seeded dev site (/neuron-test/headings, /neuron-test/columns and neuron_chat_editor).
 */
final class AgentPageContextTest extends ConcreteTestCase
{
    /** @var array{id: int, path: string, title: string, url: string} */
    private array $headings;
    /** @var array{id: int, path: string, title: string, url: string} */
    private array $columns;

    protected function setUp(): void
    {
        $this->headings = $this->contextFor('/neuron-test/headings');
        $this->columns = $this->contextFor('/neuron-test/columns');
        PromptPreview::actAs('neuron_chat_editor');
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
    }

    /**
     * @return array{id: int, path: string, title: string, url: string}
     */
    private function contextFor(string $path): array
    {
        $page = \Concrete\Core\Page\Page::getByPath($path);
        if (!$page || $page->isError()) {
            $this->markTestSkipped("The dev site has no {$path} fixture. Run documentation/fixtures/seed_test_content.php first.");
        }

        return [
            'id' => (int) $page->getCollectionID(),
            'path' => (string) $page->getCollectionPath(),
            'title' => (string) $page->getCollectionName(),
            'url' => '',
        ];
    }

    /**
     * An agent whose model is a FakeAIProvider that answers every turn, with the given history store and thread.
     *
     * @param array<string, mixed>|null $context
     * @return array{ConcreteCmsAgent, FakeAIProvider}
     */
    private function agentOn(?array $context, ?InMemoryMessageStore $store = null, ?string $thread = null): array
    {
        $provider = new FakeAIProvider(...array_fill(0, 5, new AssistantMessage('OK.')));
        $agent = (new ConcreteCmsAgent())
            ->setMessageStore($store ?? new InMemoryMessageStore())
            ->setThreadId($thread ?? 'p19-' . bin2hex(random_bytes(4)))
            ->setAiProvider($provider);
        $agent->setRuntimePageContext($context);

        return [$agent, $provider];
    }

    /**
     * Runs a turn and returns what the provider was given for it.
     */
    private function turn(ConcreteCmsAgent $agent, FakeAIProvider $provider, string $message = 'Hello'): RequestRecord
    {
        $agent->handleUserMessage($message);
        $recorded = $provider->getRecorded();

        return end($recorded);
    }

    private function sentInstructions(RequestRecord $request): string
    {
        return (string) $request->systemPrompt?->getContent();
    }

    /**
     * @return string[]
     */
    private function toolNames(RequestRecord $request): array
    {
        return array_map(static fn ($tool): string => $tool->getName(), $request->tools);
    }

    /**
     * The text of the newest user message the provider was given.
     */
    private function newestUserText(RequestRecord $request): string
    {
        $users = array_values(array_filter($request->messages, static fn (Message $m): bool => $m instanceof UserMessage));

        return (string) end($users)->getContent();
    }

    public function testNewPageContextReplacesTheOneFromAnEarlierTurn(): void
    {
        [$agent, $provider] = $this->agentOn($this->headings);
        $first = $this->sentInstructions($this->turn($agent, $provider));
        $this->assertStringContainsString('Current Page Path: /neuron-test/headings', $first);

        $agent->setRuntimePageContext($this->columns);

        $request = $this->turn($agent, $provider);
        $second = $this->sentInstructions($request);
        $this->assertStringContainsString('Current Page Path: /neuron-test/columns', $second);
        $this->assertStringContainsString('Current Page ID: ' . $this->columns['id'], $second);
        $this->assertStringNotContainsString('/neuron-test/headings', $second);
        $this->assertStringStartsWith($agent->getInstructions()->getContent() . "\n<TOOLS-GUIDELINES>", $second, 'the instructions and what is sent must agree');
        $this->assertStringStartsWith(PageContextTag::build($this->columns['id'], $this->columns['title'], $this->columns['path']) . "\n\n", $this->newestUserText($request), 'the newest message carries the new page\'s tag');
    }

    public function testToolGuidelinesAndToolListSurviveAContextChange(): void
    {
        [$agent, $provider] = $this->agentOn($this->headings);
        $toolNames = $this->toolNames($this->turn($agent, $provider));
        $this->assertNotEmpty($toolNames);

        $agent->setRuntimePageContext($this->columns);
        $request = $this->turn($agent, $provider);
        $instructions = $this->sentInstructions($request);

        $this->assertSame(1, substr_count($instructions, '<TOOLS-GUIDELINES>'), 'the guidelines block is added once, not stacked');
        $this->assertSame(1, substr_count($instructions, '</TOOLS-GUIDELINES>'));
        $this->assertSame($toolNames, $this->toolNames($request));
        $this->assertSame(count($toolNames), count(array_unique($toolNames)), 'no duplicate tool names');
    }

    public function testClearingOrRepeatingTheContextIsHarmless(): void
    {
        [$agent, $provider] = $this->agentOn($this->columns);
        $once = $this->sentInstructions($this->turn($agent, $provider));

        $agent->setRuntimePageContext($this->columns);
        $this->assertSame($once, $this->sentInstructions($this->turn($agent, $provider)), 'the same context twice gives the same instructions');

        // Under the CLI, no runtime context means no page lines (see ScenarioRunnerSupportTest), and no tag.
        $agent->setRuntimePageContext(null);
        $request = $this->turn($agent, $provider);
        $cleared = $this->sentInstructions($request);
        $this->assertStringNotContainsString('Current Page', $cleared);
        $this->assertSame(1, substr_count($cleared, '<TOOLS-GUIDELINES>'));
        $this->assertSame('Hello', $this->newestUserText($request), 'no page, no tag');

        $agent->setRuntimePageContext($this->headings);
        $headings = $this->sentInstructions($this->turn($agent, $provider));
        $this->assertStringContainsString('Current Page Path: /neuron-test/headings', $headings);
        $this->assertStringNotContainsString('/neuron-test/columns', $headings);
    }

    public function testRestoredHistoryDoesNotBringBackAnOldPageContext(): void
    {
        $store = new InMemoryMessageStore();
        $thread = 'p19-' . bin2hex(random_bytes(4));

        // Turn 1 "request": an agent on the headings page.
        [$turnOne, $providerOne] = $this->agentOn($this->headings, $store, $thread);
        $this->assertStringContainsString('/neuron-test/headings', $this->sentInstructions($this->turn($turnOne, $providerOne)));

        // What is stored is the conversation only: neither the instructions nor the page context lines.
        $stored = $store->loadAll($thread);
        $this->assertCount(2, $stored, 'the user message and the reply');
        $storedText = implode("\n", array_map(static fn (Message $m): string => (string) $m->getContent(), $stored));
        foreach (['Current Page', 'TOOLS-GUIDELINES', 'You are an AI assistant'] as $needle) {
            $this->assertStringNotContainsString($needle, $storedText, "the stored history must not contain \"{$needle}\"");
        }

        // Turn 2 "request": a new agent on the columns page, history read back from the same store and thread.
        [$turnTwo, $providerTwo] = $this->agentOn($this->columns, $store, $thread);
        $request = $this->turn($turnTwo, $providerTwo);

        $this->assertCount(3, $request->messages, 'the conversation itself is restored, then the new message');
        $instructions = $this->sentInstructions($request);
        $this->assertStringContainsString('Current Page Path: /neuron-test/columns', $instructions);
        $this->assertStringNotContainsString('/neuron-test/headings', $instructions);

        // Each user message keeps the tag of the page it was sent from ("Earlier messages may refer to other pages").
        $this->assertStringContainsString('/neuron-test/headings', (string) $request->messages[0]->getContent());
        $this->assertStringContainsString('/neuron-test/columns', $this->newestUserText($request));
    }
}
