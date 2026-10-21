<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Concrete\Core\Application\Application;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;

/**
 * Runs one scenario once: reset the fixture, act as the scenario's user, play the turns
 * through the real agent in this process (not over HTTP), and check the results.
 *
 * This is the only code that calls the AI provider. Nothing in tests/Unit or tests/Integration may use it.
 *
 * The agent is built by $agentFactory, so a stand-in agent can be used to try the runner without a provider.
 * A new agent is built for every turn, as the chat endpoint does for every request; only the chat row carries
 * the conversation from one turn to the next.
 */
final class ScenarioRunner
{
    public const CHAT_PREFIX = 'neuron-eval-';
    /** The session key controllers/chat.php stores the panel's page context under. */
    public const SESSION_KEY = 'katalysis_neuron_ai_page_context';
    /** What ConcreteCmsAgent::handle() returns when something went wrong inside it. */
    public const AGENT_ERROR_REPLY = 'I ran into an error processing that request. Please try again.';

    private const MAX_TEXT = 1000;

    /**
     * @param \Closure(): ConcreteCmsAgent $agentFactory
     */
    public function __construct(
        private Application $app,
        private Fixture $fixture,
        private SnapshotBuilder $snapshots,
        private \Closure $agentFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed> the run record (see ResultsWriter)
     *
     * @throws \RuntimeException when the fixture reset fails
     */
    public function run(array $scenario, int $runNumber): array
    {
        $started = microtime(true);
        $run = ['n' => $runNumber, 'outcome' => Expectations::ERROR, 'error' => null, 'durationSec' => 0.0, 'usage' => null, 'turns' => [], 'checks' => []];

        $this->fixture->reset();
        $this->entityManager()->clear();

        $checks = [];
        $hadError = false;
        $allCalls = [];
        $usage = null;
        $anyNotice = null;
        $setup = new ScenarioSetup();
        $setupIds = [];

        try {
            $user = $this->loginAs((string) $scenario['user']);
            // The setup comes before the snapshot, so `nothing_changed` doesn't count it as a change.
            if (isset($scenario['setup'])) {
                $setupIds = $setup->apply($scenario['setup']);
                $this->entityManager()->clear();
            }
            $start = $this->snapshots->take();
            $chatId = $this->createChat((string) $scenario['id'], $runNumber, $user, (string) $scenario['current_page']);

            $page = (string) $scenario['current_page'];
            $lastReply = '';
            $lastSay = '';
            $after = $start;

            foreach ($scenario['turns'] as $i => $turn) {
                $page = (string) ($turn['current_page'] ?? $page);

                // Every turn is a new request, as over HTTP (controllers/chat.php, getOrRestoreAgent): a new agent, its
                // history read back from the chat row (the entity cache is cleared so it really comes from the database),
                // and then the page context.
                $this->entityManager()->clear();
                $agent = ($this->agentFactory)();
                $agent->setChatId($chatId);
                $this->setPageContext($agent, $page);

                $before = $this->snapshots->take();
                $logMark = $this->maxLogID();
                $historyBefore = count($this->messages($agent));

                $turnStarted = microtime(true);
                $reply = $agent->handleUserMessage((string) $turn['say']);
                $turnSeconds = microtime(true) - $turnStarted;

                $after = $this->snapshots->take();
                $newMessages = array_slice($this->messages($agent), $historyBefore);
                $calls = $this->toolCalls($newMessages);
                $turnUsage = $this->usage($newMessages);
                $logErrors = $this->errorLogs($logMark);
                $agentError = $reply === self::AGENT_ERROR_REPLY;
                // The same call controllers/chat.php makes. Recorded for every scenario, so false notices show up everywhere.
                $notice = $agent->getTurnNotice();

                $turnChecks = [];
                if (isset($turn['expect'])) {
                    $context = new CheckContext($before, $start, $after, $calls, $reply, (string) $turn['say'], $notice);
                    foreach (Expectations::evaluate($turn['expect'], $context) as $check) {
                        $turnChecks[] = $check->at('turn ' . ($i + 1));
                    }
                }

                $checks = array_merge($checks, $turnChecks);
                $allCalls = array_merge($allCalls, $calls);
                $anyNotice ??= $notice;
                $usage = $this->addUsage($usage, $turnUsage);
                $hadError = $hadError || $agentError;
                $lastReply = $reply;
                $lastSay = (string) $turn['say'];

                $run['turns'][] = [
                    'say' => $turn['say'],
                    'page' => $page,
                    'reply' => $reply,
                    'agentError' => $agentError,
                    'notice' => $notice,
                    'durationSec' => round($turnSeconds, 1),
                    'usage' => $turnUsage,
                    'toolCalls' => array_map(fn (array $call): array => $this->truncateCall($call), $calls),
                    'logErrors' => $logErrors,
                    'checks' => array_map(static fn (CheckResult $c): array => $c->toArray(), $turnChecks),
                ];
            }

            if (isset($scenario['expect'])) {
                $context = new CheckContext($start, $start, $after, $allCalls, $lastReply, $lastSay, $anyNotice);
                foreach (Expectations::evaluate($scenario['expect'], $context) as $check) {
                    $checks[] = $check->at('end');
                }
            }
        } catch (\Throwable $e) {
            $hadError = true;
            $run['error'] = get_class($e) . ': ' . $e->getMessage();
        } finally {
            $setup->undo($setupIds);
        }

        $run['usage'] = $usage;
        $run['checks'] = array_map(static fn (CheckResult $c): array => $c->toArray(), array_filter($checks, static fn (CheckResult $c): bool => $c->where === 'end'));
        $run['outcome'] = Expectations::outcome($checks, $hadError);
        $run['durationSec'] = round(microtime(true) - $started, 1);

        return $run;
    }

    /**
     * Deletes every chat row the runner created (any session id that starts with the prefix).
     *
     * @return int rows deleted
     */
    public function deleteChats(): int
    {
        $this->entityManager()->clear();

        return (int) $this->db()->executeStatement(
            'DELETE FROM KatalysisNeuronAiChats WHERE sessionId LIKE ?',
            [self::CHAT_PREFIX . '%']
        );
    }

    public function countChats(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM KatalysisNeuronAiChats WHERE sessionId LIKE ?', [self::CHAT_PREFIX . '%']);
    }

    private function db(): Connection
    {
        return $this->app->make(Connection::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->app->make(EntityManagerInterface::class);
    }

    /**
     * Acts as this user for the rest of the process, without a session or a cookie.
     * Concrete's request-level "custom user" is what its own CLI jobs use.
     */
    private function loginAs(string $who): int
    {
        $repository = $this->app->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        if (!$info) {
            throw new \RuntimeException("The user \"{$who}\" does not exist. Run the seed script first.");
        }

        Request::getInstance()->setCustomRequestUser($info);

        return (int) $info->getUserID();
    }

    private function createChat(string $scenarioId, int $runNumber, int $userID, string $location): int
    {
        $chat = new Chat();
        $chat->setSessionId(self::CHAT_PREFIX . date('YmdHis') . '-' . $scenarioId . '-' . $runNumber);
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy($userID);
        $chat->setLocation($location);

        $em = $this->entityManager();
        $em->persist($chat);
        $em->flush();

        return (int) $chat->getId();
    }

    /**
     * Tells the agent and its tools which page the user is on, the way the chat endpoint does: it puts the
     * panel's page context in $_SESSION['katalysis_neuron_ai_page_context'] (controllers/chat.php, send_message)
     * and the tools read it from there. There is no session on the CLI, so a native one is started for the run.
     * (The tools also look at request parameters, but through a container entry that never holds the real
     * request, so those alone don't reach them.)
     */
    private function setPageContext(ConcreteCmsAgent $agent, string $path): void
    {
        $page = Page::getByPath($path);
        if (!$page || $page->isError()) {
            throw new \RuntimeException("The scenario's current page \"{$path}\" does not exist.");
        }

        $context = [
            'id' => (int) $page->getCollectionID(),
            'path' => (string) $page->getCollectionPath(),
            'title' => (string) $page->getCollectionName(),
            'url' => (string) $page->getCollectionLink(),
        ];
        $agent->setRuntimePageContext($context);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start(['use_cookies' => 0, 'use_only_cookies' => 0, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
        }
        $_SESSION[self::SESSION_KEY] = $context;
    }

    /**
     * Ends the native session started for page context and removes its file.
     */
    public function endSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * @return list<\NeuronAI\Chat\Messages\Message>
     */
    private function messages(ConcreteCmsAgent $agent): array
    {
        return array_values($agent->getChatHistory()->getMessages());
    }

    /**
     * @param list<\NeuronAI\Chat\Messages\Message> $messages
     *
     * @return list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}>
     */
    private function toolCalls(array $messages): array
    {
        // P2.1 spike: on Neuron 4 the results are on the ToolResultMessage that follows the call, matched by call ID.
        $results = [];
        foreach ($messages as $message) {
            if ($message instanceof \NeuronAI\Chat\Messages\ToolResultMessage) {
                foreach ($message->getToolCalls() as $settled) {
                    if ($settled->hasResult()) {
                        $value = $settled->getResult();
                        $results[(string) $settled->getCallId()] = $value instanceof \NeuronAI\Tools\ToolOutput ? (string) json_encode($value) : $value;
                    }
                }
            }
        }

        $calls = [];
        foreach ($messages as $message) {
            if (!$message instanceof ToolCallMessage) {
                continue;
            }
            foreach ($message->getToolCalls() as $tool) {
                $result = $results[(string) $tool->getCallId()] ?? '';
                $calls[] = [
                    'name' => $tool->getName(),
                    'inputs' => $tool->getInputs(),
                    'result' => $result,
                    'ok' => ToolCall::succeeded($result),
                ];
            }
        }

        return $calls;
    }

    /**
     * @param list<\NeuronAI\Chat\Messages\Message> $messages
     *
     * @return array{input: int, output: int}|null null when the provider reported no usage
     */
    private function usage(array $messages): ?array
    {
        $total = null;
        foreach ($messages as $message) {
            if (!$message instanceof AssistantMessage || $message->getUsage() === null) {
                continue;
            }
            $total = $this->addUsage($total, ['input' => $message->getUsage()->inputTokens, 'output' => $message->getUsage()->outputTokens]);
        }

        return $total;
    }

    /**
     * @param array{input: int, output: int}|null $a
     * @param array{input: int, output: int}|null $b
     *
     * @return array{input: int, output: int}|null
     */
    private function addUsage(?array $a, ?array $b): ?array
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return ['input' => $a['input'] + $b['input'], 'output' => $a['output'] + $b['output']];
    }

    private function maxLogID(): int
    {
        return (int) $this->db()->fetchOne('SELECT COALESCE(MAX(logID), 0) FROM Logs');
    }

    /**
     * Errors (level 400 and up) that Concrete logged since $mark. ConcreteCmsAgent::handle() hides the real cause
     * of a failure from the reply, but logs it, so this is where the table gets it from.
     *
     * @return list<string>
     */
    private function errorLogs(int $mark): array
    {
        $rows = $this->db()->fetchFirstColumn('SELECT message FROM Logs WHERE logID > ? AND level >= 400 ORDER BY logID LIMIT 20', [$mark]);

        return array_map(static fn ($m): string => mb_substr((string) $m, 0, 500), $rows);
    }

    /**
     * @param array{name: string, inputs: array<string, mixed>, result: string, ok: bool} $call
     *
     * @return array{name: string, inputs: string, result: string, ok: bool, write: bool}
     */
    private function truncateCall(array $call): array
    {
        return [
            'name' => $call['name'],
            'write' => ToolCall::isWrite($call['name']),
            'ok' => $call['ok'],
            'inputs' => mb_substr((string) json_encode($call['inputs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 0, self::MAX_TEXT),
            'result' => mb_substr($call['result'], 0, self::MAX_TEXT),
        ];
    }
}
