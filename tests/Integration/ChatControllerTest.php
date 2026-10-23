<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller\Chat;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Chat\ChatMessageStore;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\PageContextTag;
use Katalysis\NeuronAi\Security\InjectionNotice;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P2.6 (plans/P2.6.md §3, §6, §8.1): the chat endpoints on Neuron 4, called on the controller itself, as the panel
 * calls them over HTTP: the JSON body and the token header on the request, the page context the panel sends, a native
 * session for the chat. The model is Neuron's FakeAIProvider (makeAgent() is the only thing replaced), so no AI
 * provider is called and no API key is used. Everything else is real: the access check, the page context resolver,
 * the agent, its tools and scanner, the message store.
 *
 * This is the gap BF-1 exposed: the agent was tested, but what the controller hands it and returns was not.
 *
 * Needs the seeded dev site (/neuron-test/headings and /neuron-test/injection, neuron_editor, neuron_chat_editor).
 */
final class ChatControllerTest extends ConcreteTestCase
{
    private const SESSION_PREFIX = 'p26ctl-';
    private const ERROR_REPLY = 'I ran into an error processing that request. Please try again.';
    /** A made-up key. SecretRedactor must hide it wherever it is logged. */
    private const FAKE_KEY = 'sk-proj-P26FakeKeyAbCdEf1234567890';
    /** What a database failure can say: SQL, a table name, a file path, a key. None of it may reach the browser. */
    private const NASTY = "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x' in 'field list' (SQL: SELECT * FROM KatalysisNeuronAiChats WHERE id = 1)"
        . ' in /Users/someone/Herd/site/concrete/vendor/doctrine/dbal/src/Driver/PDO/Connection.php:67 with key ' . self::FAKE_KEY;

    private const POST_ACTIONS = ['send_message', 'new_chat', 'delete_chat', 'delete_current_chat'];
    private const GET_ACTIONS = ['chat_list', 'load', 'current'];

    /** Throwaway pages for the BF-7 cases, deleted in tearDown(). */
    private ?ThrowawayPages $pages = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Concrete's router finds package controllers by path; here it is loaded directly.
        require_once dirname(__DIR__, 2) . '/controllers/chat.php';
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function setUp(): void
    {
        foreach (['/neuron-test/headings', '/neuron-test/injection'] as $path) {
            $page = Page::getByPath($path);
            if (!$page || $page->isError()) {
                $this->markTestSkipped("The dev site has no {$path} fixture. Run documentation/fixtures/seed_test_content.php first.");
            }
        }

        $this->startSession();
    }

    protected function tearDown(): void
    {
        $this->pages?->restore();
        $this->pages = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->app()->make(EntityManagerInterface::class)->clear();
        $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE sessionId LIKE ?', [self::SESSION_PREFIX . '%']);
        Request::getInstance()->setCustomRequestUser(-1);
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * A fresh browser session: the controller finds "the current chat" by its ID.
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_id(self::SESSION_PREFIX . bin2hex(random_bytes(6)));
        @session_start(['use_cookies' => 0, 'use_only_cookies' => 0, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
    }

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
        // Permission answers are cached for the request.
        $this->app()->make('cache/request')->flush();
    }

    /**
     * @return array{id: int, path: string, title: string, url: string} what the panel sends for a page
     */
    private static function pageContext(string $path): array
    {
        $page = Page::getByPath($path);

        return [
            'id' => (int) $page->getCollectionID(),
            'path' => $path,
            // The browser's title is not trusted: the tag uses the page's own name.
            'title' => 'A browser title | Site',
            'url' => 'http://katalysis-pro.test' . $path,
        ];
    }

    /**
     * The controller with only the agent's provider replaced, one agent per request as over HTTP.
     *
     * @param FakeAIProvider|\Closure(): ConcreteCmsAgent $provider
     */
    private function controller(FakeAIProvider|\Closure|null $provider = null): Chat
    {
        $factory = $provider instanceof \Closure
            ? $provider
            : static fn (): ConcreteCmsAgent => (new ConcreteCmsAgent())->setAiProvider($provider ?? new FakeAIProvider());

        return new class ($factory) extends Chat {
            public function __construct(private readonly \Closure $factory)
            {
                parent::__construct();
            }

            protected function makeAgent(): ConcreteCmsAgent
            {
                return ($this->factory)();
            }
        };
    }

    /**
     * A controller whose every dependency fails with NASTY: the entity manager, the message store, the agent and the
     * session. Each action reaches at least one of them.
     */
    private function failingController(): Chat
    {
        return new class (self::NASTY) extends Chat {
            public function __construct(private readonly string $nasty)
            {
                parent::__construct();
            }

            protected function makeAgent(): ConcreteCmsAgent
            {
                throw new \RuntimeException($this->nasty);
            }

            protected function entityManager(): EntityManagerInterface
            {
                throw new \PDOException($this->nasty);
            }

            protected function messageStore(): ChatMessageStore
            {
                throw new \RuntimeException($this->nasty);
            }

            protected function newSessionId(): string
            {
                throw new \RuntimeException($this->nasty);
            }
        };
    }

    /**
     * Calls an action as the panel does and returns the status and the decoded body.
     *
     * @param array<string, mixed>|null $json the POST body
     * @param array<string, string> $query
     * @return array{int, array<string, mixed>, string} status, decoded body, raw body
     */
    private function call(Chat $controller, string $action, ?array $json = null, array $query = [], ?string $token = 'valid'): array
    {
        $isPost = in_array($action, self::POST_ACTIONS, true);
        $request = Request::create('/ccm/system/katalysis_neuron_ai/chat/' . $action, $isPost ? 'POST' : 'GET', $query, [], [], [], $json === null ? '' : (string) json_encode($json));
        if ($token !== null) {
            $request->headers->set(ChatAccess::TOKEN_HEADER, $token === 'valid' ? ChatAccess::generateToken() : $token);
        }
        $controller->setRequest($request);
        // Every call is a new request over HTTP, with a new entity manager: nothing is read from an earlier call's.
        $this->app()->make(EntityManagerInterface::class)->clear();

        $response = $controller->$action();
        $this->assertNotNull($response, "{$action} must return a response, not echo and exit.");
        $raw = (string) $response->getContent();

        return [$response->getStatusCode(), json_decode($raw, true) ?? [], $raw];
    }

    /**
     * @return array<string, mixed> one message turn's reply
     */
    private function send(Chat $controller, string $message, string $path = '/neuron-test/headings'): array
    {
        [$status, $body, $raw] = $this->call($controller, 'send_message', ['message' => $message, 'pageContext' => self::pageContext($path)]);
        $this->assertSame(200, $status, $raw);

        return $body;
    }

    private function currentChatId(): int
    {
        return (int) $this->db()->fetchOne('SELECT id FROM KatalysisNeuronAiChats WHERE sessionId = ?', [session_id()]);
    }

    /**
     * @return list<array<string, mixed>> the history the panel gets on a reload
     */
    private function reload(): array
    {
        [$status, $body, $raw] = $this->call($this->controller(), 'current');
        $this->assertSame(200, $status, $raw);

        return json_decode((string) ($body['chat']['chatHistory'] ?? '[]'), true);
    }

    /**
     * @param array<string, mixed> $message one entry of the panel's history
     */
    private static function text(array $message): string
    {
        $content = $message['content'] ?? '';

        return is_array($content) ? implode('', array_column($content, 'content')) : (string) $content;
    }

    /**
     * @return list<array{string, string}> role and text of each stored row, in order
     */
    private function storedRows(int $chatId): array
    {
        $rows = $this->db()->fetchAllAssociative('SELECT role, type, content FROM KatalysisNeuronAiChatMessages WHERE chatId = ? ORDER BY id', [$chatId]);

        return array_map(static function (array $row): array {
            $content = json_decode((string) $row['content'], true);

            return [$row['role'] . ($row['type'] !== null ? ':' . $row['type'] : ''), is_array($content) ? implode('', array_column($content, 'content')) : (string) $content];
        }, $rows);
    }

    private static function tag(string $path): string
    {
        return PageContextTag::forPage(Page::getByPath($path));
    }

    /**
     * @param Message[] $messages
     * @return string[]
     */
    private static function roles(array $messages): array
    {
        return array_map(static fn (Message $m): string => $m->getRole(), $messages);
    }

    private function assertLeaksNothing(string $raw, string $what): void
    {
        foreach (['SQLSTATE', 'SELECT', 'KatalysisNeuronAiChats', '/Users/', 'vendor/', '.php', 'sk-', 'AbCdEf1234567890', '#0 ', 'Stack trace', 'Exception'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, "{$what}: the reply contains \"{$needle}\"");
        }
    }

    private function maxLogID(): int
    {
        return (int) $this->db()->fetchOne('SELECT COALESCE(MAX(logID), 0) FROM Logs');
    }

    private function assertNoKeyLoggedSince(int $mark): void
    {
        $logged = $this->db()->fetchFirstColumn('SELECT message FROM Logs WHERE logID > ?', [$mark]);
        $this->assertNotEmpty($logged, 'the failure is logged');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('AbCdEf1234567890', (string) $line, 'a log line holds the key');
        }
    }

    // ---- Access ------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function everyActionProvider(): array
    {
        $cases = [];
        foreach (array_merge(self::POST_ACTIONS, self::GET_ACTIONS) as $action) {
            $cases[$action] = [$action];
        }

        return $cases;
    }

    #[DataProvider('everyActionProvider')]
    public function testNeuronEditorIsRefusedOnEveryAction(string $action): void
    {
        $this->actAs('neuron_editor');
        $provider = new FakeAIProvider(new AssistantMessage('Should not be reached.'));

        [$status, $body] = $this->call($this->controller($provider), $action, ['message' => 'Hello', 'id' => 1], ['id' => '1']);

        $this->assertSame(403, $status);
        $this->assertSame('access_denied', $body['code'] ?? null);
        $provider->assertCallCount(0);
        $this->assertSame(0, $this->currentChatId(), 'no chat was created');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function postActionProvider(): array
    {
        return array_combine(self::POST_ACTIONS, array_map(static fn (string $a): array => [$a], self::POST_ACTIONS));
    }

    #[DataProvider('postActionProvider')]
    public function testAPostWithoutAValidTokenIsRefused(string $action): void
    {
        $this->actAs('superuser');
        $provider = new FakeAIProvider(new AssistantMessage('Should not be reached.'));

        foreach ([null, 'not-a-token'] as $token) {
            [$status, $body] = $this->call($this->controller($provider), $action, ['message' => 'Hello', 'id' => 1], [], $token);

            $this->assertSame(403, $status);
            $this->assertSame(ChatAccess::TOKEN_ERROR_CODE, $body['code'] ?? null);
        }
        $provider->assertCallCount(0);
        $this->assertSame(0, $this->currentChatId(), 'no chat was created');
    }

    // ---- Sending -----------------------------------------------------------

    public function testATurnIsStoredWithItsPageTagAndReloadsWithoutIt(): void
    {
        $this->actAs('superuser');
        $provider = new FakeAIProvider(new AssistantMessage('You are on Headings.'));

        $body = $this->send($this->controller($provider), 'What page am I on?');

        $this->assertTrue($body['success']);
        $this->assertSame('You are on Headings.', $body['response']);
        $this->assertNull($body['notice']);
        $this->assertSame(2, $body['history_count']);

        // The model got the tag for the page the panel named, built from the page, not the browser's title.
        $sent = $provider->getRecorded()[0]->messages;
        $this->assertSame(self::tag('/neuron-test/headings') . "\n\nWhat page am I on?", end($sent)->getContent());

        // Stored with the tag, for later turns ...
        $chatId = $this->currentChatId();
        $this->assertSame([
            ['user', self::tag('/neuron-test/headings') . "\n\nWhat page am I on?"],
            ['assistant', 'You are on Headings.'],
        ], $this->storedRows($chatId));
        $this->assertSame('/neuron-test/headings', $this->db()->fetchOne('SELECT location FROM KatalysisNeuronAiChats WHERE id = ?', [$chatId]));

        // ... and shown without it on a reload.
        $history = $this->reload();
        $this->assertSame(['What page am I on?', 'You are on Headings.'], array_map([self::class, 'text'], $history));
        $this->assertSame(['user', 'assistant'], array_column($history, 'role'));
    }

    public function testEachRequestBindsANewAgentToTheSameChat(): void
    {
        $this->actAs('superuser');
        $this->send($this->controller(new FakeAIProvider(new AssistantMessage('First answer.'))), 'First question.');

        $second = new FakeAIProvider(new AssistantMessage('Second answer.'));
        $body = $this->send($this->controller($second), 'Second question.', '/neuron-test/injection');

        $this->assertSame('Second answer.', $body['response']);
        $sent = $second->getRecorded()[0]->messages;
        $this->assertSame(['user', 'assistant', 'user'], self::roles($sent), 'the second request read the first turn back');
        $this->assertSame('First answer.', $sent[1]->getContent());
        $this->assertStringStartsWith(self::tag('/neuron-test/injection'), (string) $sent[2]->getContent(), 'each turn is tagged with its own page');
        $this->assertCount(4, $this->storedRows($this->currentChatId()));
    }

    public function testTheInjectionNoticeReachesThePanelIsStoredAndComesBackOnAReload(): void
    {
        $this->actAs('superuser');
        $injection = (int) Page::getByPath('/neuron-test/injection')->getCollectionID();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('get_page_content', 'call_1', ['pageID' => $injection])]),
            new AssistantMessage('Here is a summary.'),
        );

        $body = $this->send($this->controller($provider), 'Summarise this page for me.', '/neuron-test/injection');

        $expected = InjectionNotice::text(InjectionNotice::SUSPECTED, InjectionNotice::SOURCE_PAGE);
        $this->assertSame("Some content the assistant read (this page) contains text addressed to the AI assistant. It was not acted on.", $expected);
        $this->assertSame('Here is a summary.', $body['response']);
        $this->assertSame($expected, $body['notice'], 'the reply JSON carries the notice');

        $flagged = $this->db()->fetchAllAssociative(
            "SELECT role, meta FROM KatalysisNeuronAiChatMessages WHERE chatId = ? AND meta LIKE '%" . InjectionNotice::META_KEY . "%' ORDER BY id",
            [$this->currentChatId()]
        );
        $this->assertCount(1, $flagged, 'one stored message carries the notice');
        $this->assertSame('assistant', $flagged[0]['role']);
        $this->assertStringContainsString(InjectionNotice::SUSPECTED, $flagged[0]['meta']);
        $this->assertStringContainsString('"' . InjectionNotice::META_SOURCE_KEY . '":"' . InjectionNotice::SOURCE_PAGE . '"', $flagged[0]['meta']);

        // A second turn with nothing flagged: no notice now, and the first one stays where it was.
        $next = $this->send($this->controller(new FakeAIProvider(new AssistantMessage('You are on Headings.'))), 'What page am I on?');
        $this->assertNull($next['notice']);

        $history = $this->reload();
        $withNotice = array_values(array_filter($history, static fn (array $m): bool => isset($m['notice'])));
        $this->assertCount(1, $withNotice, 'the notice shows on one message only');
        $this->assertSame($expected, $withNotice[0]['notice']);
        $this->assertSame('Here is a summary.', self::text($withNotice[0]));
        $this->assertSame('Summarise this page for me.', self::text($history[0]), 'shown without the page tag');
    }

    public function testAProviderFailureLeaksNothingAndIsStoredAsTheErrorReply(): void
    {
        $this->actAs('superuser');
        $mark = $this->maxLogID();
        $provider = new class () extends FakeAIProvider {
            public function chat(Message ...$messages): ProviderResponse
            {
                throw new \RuntimeException('Incorrect API key provided: ' . 'sk-proj-P26FakeKeyAbCdEf1234567890' . ' in /Users/someone/vendor/x.php:12');
            }
        };

        [$status, $body, $raw] = $this->call($this->controller($provider), 'send_message', ['message' => 'Hello', 'pageContext' => self::pageContext('/neuron-test/headings')]);

        $this->assertSame(200, $status);
        $this->assertTrue($body['success']);
        $this->assertSame(self::ERROR_REPLY, $body['response']);
        $this->assertLeaksNothing($raw, 'send_message');
        $this->assertNoKeyLoggedSince($mark);

        // Stored as on v3: the message and the error reply, so both show after a reload.
        $this->assertSame([
            ['user', self::tag('/neuron-test/headings') . "\n\nHello"],
            ['assistant', self::ERROR_REPLY],
        ], $this->storedRows($this->currentChatId()));
        $this->assertSame(['Hello', self::ERROR_REPLY], array_map([self::class, 'text'], $this->reload()));
    }

    public function testAFailureAfterAToolRanIsStoredAsTheErrorReplyAndTheNextTurnWorks(): void
    {
        $this->actAs('superuser');
        $headings = (int) Page::getByPath('/neuron-test/headings')->getCollectionID();
        $provider = new class (new ToolCallMessage(null, [new ToolCall('get_page_content', 'call_1', ['pageID' => $headings])])) extends FakeAIProvider {
            public function chat(Message ...$messages): ProviderResponse
            {
                if ($this->getCallCount() >= 1) {
                    throw new \RuntimeException('The provider went away.');
                }

                return parent::chat(...$messages);
            }
        };

        $body = $this->send($this->controller($provider), 'Read this page.');

        $this->assertSame(self::ERROR_REPLY, $body['response']);
        $chatId = $this->currentChatId();
        $this->assertSame([
            ['user', self::tag('/neuron-test/headings') . "\n\nRead this page."],
            ['assistant', self::ERROR_REPLY],
        ], $this->storedRows($chatId), 'no tool pair (Neuron commits it only after the next call), no second user message');

        $mark = $this->maxLogID();
        $next = new FakeAIProvider(new AssistantMessage('Fine now.'));
        $this->assertSame('Fine now.', $this->send($this->controller($next), 'Try again.')['response']);
        $sent = $next->getRecorded()[0]->messages;
        $this->assertSame(['user', 'assistant', 'user'], self::roles($sent));
        (new HistoryTrimmer())->trim($sent, PHP_INT_MAX);
        $this->assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM Logs WHERE logID > ? AND message LIKE '%placeholders added%'", [$mark]), 'nothing needed repairing');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badMessageProvider(): array
    {
        return ['missing' => [null], 'empty' => [''], 'spaces only' => ['   '], 'an array' => [['Hello']], 'a number' => [42]];
    }

    #[DataProvider('badMessageProvider')]
    public function testAMessageThatIsNotTextIsABadRequest(mixed $message): void
    {
        $this->actAs('superuser');
        $provider = new FakeAIProvider(new AssistantMessage('Should not be reached.'));

        [$status, $body] = $this->call($this->controller($provider), 'send_message', $message === null ? [] : ['message' => $message]);

        $this->assertSame(400, $status);
        $this->assertSame(['success' => false, 'error' => 'Message is required'], $body);
        $provider->assertCallCount(0);
    }

    // ---- Every action's error response -------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function failureProvider(): array
    {
        return [
            'send_message' => ['send_message', 'Unable to process the chat request at the moment.'],
            'chat_list' => ['chat_list', 'Unable to load your chats.'],
            'load' => ['load', 'Unable to load this chat.'],
            'current' => ['current', 'Unable to load the current chat.'],
            'new_chat' => ['new_chat', 'Unable to start a new chat.'],
            'delete_chat' => ['delete_chat', 'Unable to delete this chat.'],
            'delete_current_chat' => ['delete_current_chat', 'Unable to delete the current chat.'],
        ];
    }

    #[DataProvider('failureProvider')]
    public function testAFailureInAnyActionReturnsOnlyAFixedMessage(string $action, string $message): void
    {
        $this->actAs('superuser');
        $mark = $this->maxLogID();

        [$status, $body, $raw] = $this->call($this->failingController(), $action, ['message' => 'Hello', 'id' => 1], ['id' => '1']);

        $this->assertSame(500, $status, $raw);
        $this->assertSame(['success' => false, 'error' => $message], $body);
        $this->assertLeaksNothing($raw, $action);
        $this->assertNoKeyLoggedSince($mark);
    }

    // ---- Reading and deleting chats ----------------------------------------

    public function testListLoadAndDeleteWorkOnTheStoredRows(): void
    {
        $this->actAs('superuser');
        $this->send($this->controller(new FakeAIProvider(new AssistantMessage('Hello back.'))), 'Hello');
        $chatId = $this->currentChatId();

        [, $list] = $this->call($this->controller(), 'chat_list');
        $listed = array_values(array_filter($list['chats'], static fn (array $c): bool => $c['id'] === $chatId));
        $this->assertCount(1, $listed);
        $this->assertSame('Hello', $listed[0]['firstMessage']);
        $this->assertSame(1, $listed[0]['userMessageCount']);

        [$status, $loaded] = $this->call($this->controller(), 'load', null, ['id' => (string) $chatId]);
        $this->assertSame(200, $status);
        $this->assertSame(['Hello', 'Hello back.'], array_map([self::class, 'text'], json_decode($loaded['chat']['chatHistory'], true)));

        [$status, $deleted] = $this->call($this->controller(), 'delete_chat', ['id' => $chatId]);
        $this->assertSame(200, $status);
        $this->assertTrue($deleted['deleted']);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiChats WHERE id = ?', [$chatId]));
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiChatMessages WHERE chatId = ?', [$chatId]), 'its messages went with it');
    }

    public function testDeleteCurrentChatRemovesTheSessionsChatAndItsMessages(): void
    {
        $this->actAs('superuser');
        $this->send($this->controller(new FakeAIProvider(new AssistantMessage('Hello back.'))), 'Hello');
        $chatId = $this->currentChatId();

        [$status, $body] = $this->call($this->controller(), 'delete_current_chat');

        $this->assertSame(200, $status);
        $this->assertTrue($body['deleted']);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiChatMessages WHERE chatId = ?', [$chatId]));
        [, $current] = $this->call($this->controller(), 'current');
        $this->assertNull($current['chat']);
    }

    public function testAChatEditorUsesTheirOwnChatsOnly(): void
    {
        $this->actAs('superuser');
        $this->send($this->controller(new FakeAIProvider(new AssistantMessage('Admin reply.'))), 'Admin question.');
        $adminChat = $this->currentChatId();

        $this->startSession();
        $this->actAs('neuron_chat_editor');
        $body = $this->send($this->controller(new FakeAIProvider(new AssistantMessage('Editor reply.'))), 'Editor question.');
        $this->assertSame('Editor reply.', $body['response']);
        $editorChat = $this->currentChatId();
        $editorID = (int) $this->app()->make(UserInfoRepository::class)->getByUserName('neuron_chat_editor')->getUserID();
        $this->assertSame($editorID, (int) $this->db()->fetchOne('SELECT createdBy FROM KatalysisNeuronAiChats WHERE id = ?', [$editorChat]));

        [, $list] = $this->call($this->controller(), 'chat_list');
        $ids = array_column($list['chats'], 'id');
        $this->assertContains($editorChat, $ids);
        $this->assertNotContains($adminChat, $ids);
        foreach ($ids as $id) {
            $this->assertSame($editorID, (int) $this->db()->fetchOne('SELECT createdBy FROM KatalysisNeuronAiChats WHERE id = ?', [$id]));
        }

        [$status, $loaded] = $this->call($this->controller(), 'load', null, ['id' => (string) $adminChat]);
        $this->assertSame(404, $status);
        $this->assertFalse($loaded['success']);
        [$status] = $this->call($this->controller(), 'delete_chat', ['id' => $adminChat]);
        $this->assertSame(404, $status);
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiChats WHERE id = ?', [$adminChat]), "the admin's chat is still there");
    }

    // ---- BF-7: the pages a turn changed, and blocks open in the user's editor --------------------------------------

    public function testATurnWithNoWritesReportsNoChanges(): void
    {
        $this->actAs('superuser');

        $body = $this->send($this->controller(new FakeAIProvider(new AssistantMessage('Hello.'))), 'Hello.');

        $this->assertSame([], $body['changes']);
    }

    /**
     * A formatting-only edit of the block on the throwaway page, as the model would call it.
     */
    private function editBlockCall(int $cID, int $bID): ToolCallMessage
    {
        $html = trim((string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$bID]));

        return new ToolCallMessage(null, [new ToolCall('update_block_in_page_area', 'call_' . bin2hex(random_bytes(3)), [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => (string) preg_replace('/^<p>(.*)<\/p>$/s', '<h2>$1</h2>', $html)],
            'changeMode' => 'formatting_only',
        ])]);
    }

    public function testATurnThatChangesThePageReportsItSoThePanelCanReload(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        $page = $this->pages->create('Controller changes');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $this->actAs('superuser');

        $body = $this->send(
            $this->controller(new FakeAIProvider($this->editBlockCall($cID, $b1), new AssistantMessage('Done.'))),
            'Make it a heading.',
            (string) $page->getCollectionPath()
        );

        $this->assertSame('Done.', $body['response']);
        $this->assertCount(1, $body['changes']);
        $this->assertSame($cID, $body['changes'][0]['pageID']);
        $this->assertSame(['update_block_in_page_area'], $body['changes'][0]['tools']);
    }

    public function testAFailedTurnStillReportsTheChangeItMadeBeforeFailing(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        $page = $this->pages->create('Controller changes, failed');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $this->actAs('superuser');
        $provider = new class ($this->editBlockCall($cID, $b1)) extends FakeAIProvider {
            public function chat(Message ...$messages): ProviderResponse
            {
                if ($this->getCallCount() >= 1) {
                    throw new \RuntimeException('The provider went away.');
                }

                return parent::chat(...$messages);
            }
        };

        $body = $this->send($this->controller($provider), 'Make it a heading.', (string) $page->getCollectionPath());

        $this->assertSame(self::ERROR_REPLY, $body['response']);
        $this->assertSame($cID, $body['changes'][0]['pageID'] ?? null);
    }

    public function testABlockOpenInTheUsersEditorIsNotChanged(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        $page = $this->pages->create('Controller open editor');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $before = $this->pages->state($cID);
        $this->actAs('superuser');
        $provider = new FakeAIProvider($this->editBlockCall($cID, $b1), new AssistantMessage('It is open in your editor.'));

        [$status, $body, $raw] = $this->call($this->controller($provider), 'send_message', [
            'message' => 'Make it a heading.',
            'pageContext' => self::pageContext((string) $page->getCollectionPath()),
            'openBlockIDs' => [$b1, 'not a number', -4],
        ]);

        $this->assertSame(200, $status, $raw);
        $this->assertSame([], $body['changes']);
        $this->assertSame($before, $this->pages->state($cID), 'the block open in the editor is unchanged, and no draft was made');
        $toolResults = (string) $this->db()->fetchOne(
            "SELECT GROUP_CONCAT(CONCAT(COALESCE(content, ''), COALESCE(meta, ''))) FROM KatalysisNeuronAiChatMessages WHERE chatId = ?",
            [$this->currentChatId()]
        );
        $this->assertStringContainsString("is open in the user's editor", $toolResults);
    }

    public function testOpenBlockIDsApplyToThatRequestOnly(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        $page = $this->pages->create('Controller open editor, next request');
        $cID = (int) $page->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $this->actAs('superuser');
        $path = (string) $page->getCollectionPath();
        $this->call($this->controller(new FakeAIProvider(new AssistantMessage('Noted.'))), 'send_message', [
            'message' => 'Hello.', 'pageContext' => self::pageContext($path), 'openBlockIDs' => [$b1],
        ]);

        $body = $this->send($this->controller(new FakeAIProvider($this->editBlockCall($cID, $b1), new AssistantMessage('Done.'))), 'Now change it.', $path);

        $this->assertSame($cID, $body['changes'][0]['pageID'] ?? null, 'the editor was closed by the next message: the block may change');
    }
}
