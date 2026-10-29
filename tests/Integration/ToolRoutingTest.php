<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Package\PackageService;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Agent\GroupRouter;
use Katalysis\NeuronAi\Agent\RoutedOpenAI;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\ToolGroups;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P3.2-2 (plans/P3.2-2.md §1–3): each request offers the "always" tools and the routed groups, at most 25, with real
 * turns of the agent and no AI provider call (FakeAIProvider, or Neuron's OpenAI provider on a stub HTTP client).
 *
 * - Every group fits with the "always" tools for every tier, so get_tool_group can always add it.
 * - get_tool_group adds its group from the model's next step in the same turn.
 * - A pause for approval keeps the groups; a denied tool stays refused after get_tool_group (the loop guard).
 * - A call to a tool the request didn't offer runs as any call does: a read runs, a write waits for its card.
 *
 * Also the guard on GroupedChatNode, which overrides ChatNode (a Neuron internal): if a turn's requests stop going
 * through it, these fail.
 */
final class ToolRoutingTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    /** @var int[] */
    private array $chatIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        $this->actAs('superuser');
    }

    protected function tearDown(): void
    {
        foreach ($this->chatIds as $id) {
            (new ConcreteCmsAgent())->setChatId($id)->discardRun();
            $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $this->chatIds = [];
        $this->pages->restore();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function userProvider(): array
    {
        return [
            'Tier 1 (neuron_content_editor)' => ['neuron_content_editor'],
            'the superuser (every tool, katalysis_pro\'s too)' => ['superuser'],
        ];
    }

    #[DataProvider('userProvider')]
    public function testEveryGroupFitsWithTheAlwaysToolsAndNoRequestOffersMoreThan25(string $user): void
    {
        $this->actAs($user);
        $registry = $this->registry(new ConcreteCmsAgent());
        $sizes = ToolGroups::sizes($registry);

        $this->assertSame(count(ToolGroups::ALWAYS), $sizes['always'], 'every "always" tool is offered at every tier');
        foreach (ToolGroups::ORDER as $group) {
            $this->assertLessThanOrEqual(ToolGroups::MAX_TOOLS, $sizes['always'] + $sizes[$group], "{$group} ({$sizes[$group]}) fits");
            $this->assertSame([$group], GroupRouter::route('', null, [$group], [$group => $sizes[$group], 'always' => $sizes['always']] + array_fill_keys(ToolGroups::ORDER, ToolGroups::MAX_TOOLS)), "get_tool_group can add {$group}");
        }
        foreach (array_keys(GroupRouter::RULES) as $group) {
            $offered = ToolGroups::offer($registry, GroupRouter::route("{$group} {$group}", 'pages content', [], $sizes));
            $this->assertLessThanOrEqual(ToolGroups::MAX_TOOLS, count($offered));
        }
    }

    public function testATurnOffersTheAlwaysToolsAndTheRoutedGroupsInTheFixedOrder(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Done.'));
        $this->previewAgent($provider)->handleUserMessage('Change all the h3 headings on this page to h2.');

        $sent = self::names($provider->getRecorded()[0]->tools);
        $this->assertLessThanOrEqual(ToolGroups::MAX_TOOLS, count($sent));
        $this->assertSame(ToolGroups::ALWAYS, array_slice($sent, 0, count(ToolGroups::ALWAYS)), 'the "always" tools first, in their order');
        $this->assertContains('batch_update_content_headings', $sent);
        $this->assertNotContains('list_topic_trees', $sent);
        $this->assertSame(array_values(array_intersect([...ToolGroups::ALWAYS, ...array_merge(...array_values(ToolGroups::GROUPS))], $sent)), $sent, 'each group in ToolGroups\' order');

        // P3.7-2: only external toolkits have guidelines, and none is offered here (PromptCutTest covers one that is).
        $this->assertStringNotContainsString('<TOOLS-GUIDELINES>', (string) $provider->getRecorded()[0]->systemPrompt?->getContent());
    }

    public function testGetToolGroupAddsTheGroupFromTheModelsNextStepInTheSameTurn(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('get_tool_group', 'call_group', ['group' => 'topics'])]),
            new AssistantMessage('Done.')
        );
        $this->previewAgent($provider)->handleUserMessage('Hello');

        [$first, $second] = $provider->getRecorded();
        $this->assertNotContains('list_topic_trees', self::names($first->tools));
        $this->assertContains('list_topic_trees', self::names($second->tools));
        $this->assertLessThanOrEqual(ToolGroups::MAX_TOOLS, count($second->tools));
        $result = self::results($provider)['call_group'] ?? '';
        $this->assertStringContainsString('"ok":true', $result);
        // The user's tools in the group, so a model looking for a tool that doesn't exist learns it from the list.
        $this->assertStringContainsString('"list_topic_trees"', $result);
        $this->assertStringNotContainsString('delete_topic', $result, 'a disabled tool is not listed');
    }

    public function testAPauseKeepsTheGroupsAndADeniedToolStaysRefusedAfterGetToolGroup(): void
    {
        $page = $this->pages->create('Routing deny');
        $cID = (int) $page->getCollectionID();
        $name = (string) $page->getCollectionName();
        $chatId = $this->newChat();
        $first = new ToolCall('update_page', 'call_' . bin2hex(random_bytes(4)), ['pageID' => $cID, 'name' => 'First try']);

        $before = new FakeAIProvider(new ToolCallMessage(null, [$first]));
        $this->chatAgent($chatId, $before)->handleUserMessage('Rename it.');

        $again = new ToolCall('update_page', 'call_again', ['pageID' => $cID, 'name' => 'Second try']);
        $after = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('get_tool_group', 'call_group', ['group' => 'pages'])]),
            new ToolCallMessage(null, [$again]),
            new AssistantMessage('What would you like instead?')
        );
        $this->chatAgent($chatId, $after)->decide((string) $first->getCallId(), false);

        $this->assertSame(self::names($before->getRecorded()[0]->tools), self::names($after->getRecorded()[0]->tools), 'the same tools after the pause');
        $this->assertStringStartsWith('TOOL NOT EXECUTED. The user declined update_page', self::results($after)['call_again'] ?? '');
        $this->assertSame($name, (string) \Concrete\Core\Page\Page::getByID($cID, 'RECENT')->getCollectionName(), 'nothing renamed');
    }

    public function testACallToAToolNotOfferedRunsAsAnyCallDoes(): void
    {
        $client = self::stubClient(
            self::toolCallResponse('call_read', 'list_topic_trees', '{}'),
            self::toolCallResponse('call_write', 'create_file_folder', '{"name":"P3.2-2 not offered"}'),
        );
        $chatId = $this->newChat();
        $agent = $this->chatAgent($chatId, new RoutedOpenAI(key: 'p322-not-a-key', model: 'gpt-4o', httpClient: $client));

        $reply = $agent->handleUserMessage('Hello');

        $body = static fn (int $i): array => (array) (is_string($client->requests[$i]->body) ? json_decode($client->requests[$i]->body, true) : $client->requests[$i]->body);
        $first = $body(0);
        $offered = array_map(static fn (array $tool): string => $tool['function']['name'], $first['tools'] ?? []);
        $this->assertNotContains('list_topic_trees', $offered, 'Precondition: not offered');
        $this->assertNotContains('create_file_folder', $offered, 'Precondition: not offered');

        // The read ran: its result went back to the model.
        $this->assertStringContainsString('"tool_call_id":"call_read"', (string) json_encode($body(1), JSON_UNESCAPED_SLASHES));
        // The write waits for the user's card, as it would if it had been offered.
        $this->assertSame(ConcreteCmsAgent::AWAITING_REPLY, $reply);
        $this->assertSame(['create_file_folder'], array_column($this->db()->fetchAllAssociative('SELECT toolName FROM KatalysisNeuronAiApprovals WHERE chatId = ?', [$chatId]), 'toolName'));
    }

    // ---- Helpers ------------------------------------------------------------

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
        $this->app()->make('cache/request')->flush();
    }

    private function newChat(): int
    {
        $chat = new Chat();
        $chat->setSessionId('neuron-test-p322-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(USER_SUPER_ID);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        return (int) $chat->getId();
    }

    private function chatAgent(int $chatId, \NeuronAI\Providers\AIProviderInterface $provider): ConcreteCmsAgent
    {
        $this->app()->make(EntityManagerInterface::class)->clear();
        CacheLocal::flush();

        return (new ConcreteCmsAgent())->setChatId($chatId)->setAiProvider($provider);
    }

    private function previewAgent(FakeAIProvider $provider): ConcreteCmsAgent
    {
        return (new ConcreteCmsAgent())
            ->setMessageStore(new InMemoryMessageStore())
            ->setThreadId('p322-routing-' . bin2hex(random_bytes(4)))
            ->setAiProvider($provider);
    }

    /**
     * @return list<ToolInterface>
     */
    private function registry(ConcreteCmsAgent $agent): array
    {
        $tools = [];
        foreach ($agent->getTools() as $item) {
            foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @param array<int, mixed> $tools
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_values(array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools));
    }

    /** @return array<string, string> each call's result as the provider was sent it, by call ID */
    private static function results(FakeAIProvider $provider): array
    {
        $results = [];
        foreach ($provider->getRecorded() as $request) {
            foreach ($request->messages as $message) {
                if ($message instanceof ToolResultMessage) {
                    foreach ($message->getToolCalls() as $call) {
                        $results[(string) $call->getCallId()] = (string) $call->getResult();
                    }
                }
            }
        }

        return $results;
    }

    private static function toolCallResponse(string $id, string $tool, string $arguments): string
    {
        return (string) json_encode(['choices' => [[
            'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => $id,
                'type' => 'function',
                'function' => ['name' => $tool, 'arguments' => $arguments],
            ]]],
            'finish_reason' => 'tool_calls',
        ]]]);
    }

    private static function stubClient(string ...$responses): HttpClientInterface
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
        $client->responses = $responses;

        return $client;
    }
}
