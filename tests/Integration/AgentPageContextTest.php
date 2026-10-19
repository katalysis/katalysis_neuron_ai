<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * P1.9: the page the user is on reaches the agent's instructions afresh every time the context is set, and a
 * restored chat history does not carry an old page context with it (B-CTX-1).
 *
 * Neuron builds the start event (and with it the instructions) once, on the first chat(), and keeps it. The
 * instructions hold the page context, so ConcreteCmsAgent::setRuntimePageContext() has to drop that cache.
 *
 * These tests build the instructions but never call chat(), so they don't reach the AI provider or use an API key.
 * They write only chat rows whose session id starts with CHAT_PREFIX, and remove them afterwards.
 *
 * Needs the seeded dev site (/neuron-test/headings and /neuron-test/columns).
 */
final class AgentPageContextTest extends ConcreteTestCase
{
    private const CHAT_PREFIX = 'neuron-phpunit-p19-';

    /** @var array{id: int, path: string, title: string, url: string} */
    private array $headings;
    /** @var array{id: int, path: string, title: string, url: string} */
    private array $columns;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Concrete starts installed packages (and maps their entities) only when it runs a request. PHPUnit boots
        // without that, so map this package's Chat entity here, the way Application::setupPackages() does.
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }
    protected function setUp(): void
    {
        $this->headings = $this->contextFor('/neuron-test/headings');
        $this->columns = $this->contextFor('/neuron-test/columns');
    }

    protected function tearDown(): void
    {
        $this->entityManager()->clear();
        $this->app()->make(Connection::class)->executeStatement(
            'DELETE FROM KatalysisNeuronAiChats WHERE sessionId LIKE ?',
            [self::CHAT_PREFIX . '%']
        );
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

    private function entityManager(): EntityManagerInterface
    {
        return $this->app()->make(EntityManagerInterface::class);
    }

    private function createChat(): int
    {
        $chat = new Chat();
        $chat->setSessionId(self::CHAT_PREFIX . bin2hex(random_bytes(6)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(0);
        $chat->setLocation($this->headings['path']);

        $this->entityManager()->persist($chat);
        $this->entityManager()->flush();

        return (int) $chat->getId();
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function agentOn(?array $context): ConcreteCmsAgent
    {
        $agent = new ConcreteCmsAgent();
        $agent->setRuntimePageContext($context);

        return $agent;
    }

    /**
     * The instructions the provider would be sent for the next chat().
     */
    private function sentInstructions(ConcreteCmsAgent $agent): string
    {
        return $agent->getStartEvent()->instructions;
    }

    public function testNewPageContextReplacesTheOneFromAnEarlierTurn(): void
    {
        $agent = $this->agentOn($this->headings);
        $first = $this->sentInstructions($agent);
        $this->assertStringContainsString('Current Page Path: /neuron-test/headings', $first);

        $agent->setRuntimePageContext($this->columns);

        $second = $this->sentInstructions($agent);
        $this->assertStringContainsString('Current Page Path: /neuron-test/columns', $second);
        $this->assertStringContainsString('Current Page ID: ' . $this->columns['id'], $second);
        $this->assertStringNotContainsString('/neuron-test/headings', $second);
        $this->assertSame($second, $agent->resolveInstructions(), 'the start event and resolveInstructions() must agree');
    }

    public function testToolGuidelinesAndToolListSurviveAContextChange(): void
    {
        $agent = $this->agentOn($this->headings);
        $this->sentInstructions($agent);
        $toolNames = array_map(static fn ($tool): string => $tool->getName(), $agent->bootstrapTools());
        $this->assertNotEmpty($toolNames);

        $agent->setRuntimePageContext($this->columns);
        $instructions = $this->sentInstructions($agent);

        $this->assertSame(1, substr_count($instructions, '<TOOLS-GUIDELINES>'), 'the guidelines block is added once, not stacked');
        $this->assertSame(1, substr_count($instructions, '</TOOLS-GUIDELINES>'));
        $this->assertSame($toolNames, array_map(static fn ($tool): string => $tool->getName(), $agent->bootstrapTools()));
        $this->assertSame(count($toolNames), count(array_unique($toolNames)), 'no duplicate tool names');
    }

    public function testClearingOrRepeatingTheContextIsHarmless(): void
    {
        $agent = $this->agentOn($this->columns);
        $once = $this->sentInstructions($agent);

        $agent->setRuntimePageContext($this->columns);
        $this->assertSame($once, $this->sentInstructions($agent), 'the same context twice gives the same instructions');

        // Under the CLI, no runtime context means no page lines (see ScenarioRunnerSupportTest).
        $agent->setRuntimePageContext(null);
        $cleared = $this->sentInstructions($agent);
        $this->assertStringNotContainsString('Current Page', $cleared);
        $this->assertSame(1, substr_count($cleared, '<TOOLS-GUIDELINES>'));

        $agent->setRuntimePageContext($this->headings);
        $this->assertStringContainsString('Current Page Path: /neuron-test/headings', $this->sentInstructions($agent));
        $this->assertStringNotContainsString('/neuron-test/columns', $this->sentInstructions($agent));
    }

    public function testRestoredHistoryDoesNotBringBackAnOldPageContext(): void
    {
        $chatId = $this->createChat();

        // Turn 1 "request": an agent on the headings page builds its instructions, and the exchange is stored.
        $turnOne = $this->agentOn($this->headings);
        $turnOne->setChatId($chatId);
        $this->assertStringContainsString('/neuron-test/headings', $this->sentInstructions($turnOne));
        $history = $turnOne->resolveState()->getChatHistory();
        $history->addMessage(new UserMessage('Hello'));
        $history->addMessage(new AssistantMessage('Hi, how can I help?'));

        // What is stored is the conversation only: neither the instructions nor the page context.
        $stored = (string) $this->app()->make(Connection::class)->fetchOne('SELECT chatHistory FROM KatalysisNeuronAiChats WHERE id = ?', [$chatId]);
        $this->assertStringContainsString('Hello', $stored);
        foreach (['Current Page', 'neuron-test/headings', 'TOOLS-GUIDELINES', 'You are an AI assistant'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored, "the stored history must not contain \"{$needle}\"");
        }

        // Turn 2 "request": a new agent on the columns page, history read back from the row.
        $this->entityManager()->clear();
        $turnTwo = new ConcreteCmsAgent();
        $turnTwo->setChatId($chatId);
        $turnTwo->setRuntimePageContext($this->columns);

        $this->assertCount(2, $turnTwo->resolveState()->getChatHistory()->getMessages(), 'the conversation itself is restored');
        $instructions = $this->sentInstructions($turnTwo);
        $this->assertStringContainsString('Current Page Path: /neuron-test/columns', $instructions);
        $this->assertStringNotContainsString('/neuron-test/headings', $instructions);
    }
}
