<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\Approval\ApprovalLogListener;
use Katalysis\NeuronAi\Chat\TurnChanges;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Observability\ListenerRegistry;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Providers\AIProviderInterface;
use Katalysis\NeuronAi\Agent\RoutedOpenAI;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\AssertionFailedError;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * P2.7 (plans/P2.7.md §2, §6.1): nothing sends conversation data, prompts or keys off the site unless we configure it.
 * Neuron 4 attaches no listener and no forwarding dispatcher to an agent, monitoring environment variables (Neuron 3's
 * Inspector ones) switch nothing on, and the only requests a turn makes are the provider's.
 *
 * Only the core toolkit list and the provider are replaced: one harmless tool, and either FakeAIProvider or Neuron's
 * real OpenAI provider (the agent's RoutedOpenAI, P3.2-2) with a dummy key and a recording HTTP client. No network,
 * no API key, no chat stored.
 */
final class OutboundDataTest extends ConcreteTestCase
{
    private const MONITORING_ENV = [
        'INSPECTOR_INGESTION_KEY' => 'p27-not-a-key',
        'INSPECTOR_URL' => 'https://ingest.example.invalid',
        'INSPECTOR_TRANSPORT' => 'sync',
        'NEURON_SPLIT_MONITORING' => '1',
        'NEURON_AUTOFLUSH' => '1',
    ];

    /** @var array<string, callable> */
    private array $savedFactories = [];

    protected function setUp(): void
    {
        // External toolkits (katalysis_pro) are not what this test is about.
        $property = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $this->savedFactories = $property->getValue();
        $property->setValue(null, []);
        // P3.2-1: the agent offers each user only their tier's tools, and a tool with no tier (this test's) only at
        // Tier 3: the super user.
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, $this->savedFactories);
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private function agent(AIProviderInterface $provider): ConcreteCmsAgent
    {
        return (new class ($provider) extends ConcreteCmsAgent {
            public function __construct(private readonly AIProviderInterface $stub)
            {
                parent::__construct();
            }

            protected function coreToolkits(): array
            {
                return [new class () extends AbstractToolkit {
                    public function provide(): array
                    {
                        return [new CallableTool('read_note', 'Reads a note.', static fn (): array => [
                            'ok' => true,
                            'data' => ['note' => 'Hello.'],
                        ])];
                    }
                }];
            }

            protected function provider(): AIProviderInterface
            {
                return $this->stub;
            }
        })->setThreadId('p27-outbound-data-test');
    }

    /**
     * Fails unless the agent has no listener other than its own TurnChanges and ApprovalLogListener on ToolCalled (BF-7:
     * TurnChanges keeps the pages a turn changed in memory for the chat response; P3.6-1: the log listener writes
     * redacted lines to Concrete's own log, on the site. Neither sends anything anywhere; the request tests below run
     * with both attached), and no external dispatcher. Reads Neuron internals (HandleDispatcher): if a Neuron upgrade
     * renames them, this fails, and the upgrade must check observability again.
     */
    private function assertNothingAttached(ConcreteCmsAgent $agent): void
    {
        $registry = (new \ReflectionProperty(Workflow::class, 'listeners'))->getValue($agent);
        if ($registry !== null) {
            $this->assertInstanceOf(ListenerRegistry::class, $registry);
            $listeners = (new \ReflectionProperty(ListenerRegistry::class, 'listeners'))->getValue($registry);
            $this->assertSame([ToolCalled::class], array_keys($listeners), 'no listener but TurnChanges and the log listener');
            $this->assertCount(2, $listeners[ToolCalled::class], 'no listener but TurnChanges and the log listener');
            $this->assertInstanceOf(TurnChanges::class, $listeners[ToolCalled::class][0], 'no listener but TurnChanges and the log listener');
            $this->assertInstanceOf(ApprovalLogListener::class, $listeners[ToolCalled::class][1], 'no listener but TurnChanges and the log listener');
        }
        $this->assertNull((new \ReflectionProperty(Workflow::class, 'externalDispatcher'))->getValue($agent), 'no forwarding dispatcher');
    }

    private static function toolThenReply(): FakeAIProvider
    {
        return new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('read_note', 'call_1', [])]),
            new AssistantMessage('Done.'),
        );
    }

    public function testAnAgentAttachesNoListenerAndForwardsNothing(): void
    {
        $this->assertNothingAttached(new ConcreteCmsAgent());

        $provider = self::toolThenReply();
        $agent = $this->agent($provider);
        $agent->chat(new UserMessage('Read the note.'));

        $provider->assertCallCount(2);
        $this->assertNothingAttached($agent);
    }

    public function testTheCheckSeesAListenerOrAForwardingDispatcher(): void
    {
        $listening = (new ConcreteCmsAgent())->subscribe(ObservabilityEvent::class, static fn () => null);
        $forwarding = (new ConcreteCmsAgent())->setEventDispatcher(new class () implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        });

        foreach (['listener' => $listening, 'forwarding dispatcher' => $forwarding] as $what => $agent) {
            try {
                $this->assertNothingAttached($agent);
            } catch (AssertionFailedError) {
                continue;
            }
            $this->fail("assertNothingAttached() missed a {$what}");
        }
    }

    public function testMonitoringEnvironmentVariablesSwitchNothingOn(): void
    {
        $saved = [];
        foreach (self::MONITORING_ENV as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, getenv($name)];
            $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }

        try {
            $provider = self::toolThenReply();
            $agent = $this->agent($provider);
            $agent->chat(new UserMessage('Read the note.'));

            $provider->assertCallCount(2);
            $this->assertNothingAttached($agent);
            $inspector = array_filter(get_declared_classes(), static fn (string $class): bool => str_starts_with($class, 'Inspector\\'));
            $this->assertSame([], array_values($inspector), 'no Inspector class is loaded');
        } finally {
            foreach ($saved as $name => [$env, $process]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                putenv($process === false ? $name : $name . '=' . $process);
            }
        }
    }

    public function testTheOnlyRequestsATurnMakesAreToTheProvider(): void
    {
        $client = new class () implements HttpClientInterface {
            /** @var HttpRequest[] */
            public array $requests = [];

            /** @var string[] */
            public array $responses = [];

            public function request(HttpRequest $request): HttpResponse
            {
                $this->requests[] = $request;

                return new HttpResponse(200, (string) array_shift($this->responses), ['Content-Type' => 'application/json']);
            }

            public function stream(HttpRequest $request): StreamInterface
            {
                throw new \LogicException('The agent does not stream.');
            }
        };
        $client->responses = [
            (string) json_encode(['choices' => [[
                'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'read_note', 'arguments' => '{}'],
                ]]],
                'finish_reason' => 'tool_calls',
            ]]]),
            (string) json_encode(['choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Done.'],
                'finish_reason' => 'stop',
            ]]]),
        ];

        // P3.2-2: the provider the agent uses (RoutedOpenAI): read_note, the only tool, isn't offered for this message.
        $agent = $this->agent(new RoutedOpenAI(key: 'p27-not-a-key', model: 'gpt-4o', httpClient: $client));
        $agent->chat(new UserMessage('Read the note.'));

        $sent = array_map(static fn (HttpRequest $r): string => $r->method->value . ' ' . $r->uri, $client->requests);
        $this->assertSame([
            'POST https://api.openai.com/v1/chat/completions',
            'POST https://api.openai.com/v1/chat/completions',
        ], $sent);
    }
}
