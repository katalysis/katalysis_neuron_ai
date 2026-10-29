<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Concrete\Core\Application\Application;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Access\NeuronPermissions;
use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\PageContextResolver;
use Katalysis\NeuronAi\Entity\Chat;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolOutput;

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
    public const AGENT_ERROR_REPLY = ConcreteCmsAgent::ERROR_REPLY;

    private const MAX_TEXT = 1000;
    /** P3.6-1: how many times one turn may pause for approval before the runner stops answering. */
    private const MAX_APPROVAL_ROUNDS = 5;

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
        $allApprovals = [];
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
            $says = [];
            $after = $start;

            foreach ($scenario['turns'] as $i => $turn) {
                $page = (string) ($turn['current_page'] ?? $page);

                // Every turn is a new request, as over HTTP (controllers/chat.php, agentForThisRequest): a new agent,
                // bound to the chat before anything else (Neuron 4 runs no turn without a thread, and binds an agent's
                // thread once), its history read back from the chat's rows (the entity cache is cleared so it really
                // comes from the database), and then the page context.
                $this->entityManager()->clear();
                $agent = ($this->agentFactory)();
                $agent->setChatId($chatId);
                $this->setPageContext($agent, $page);

                $before = $this->snapshots->take();
                $logMark = $this->maxLogID();
                $idsBefore = $this->messageIds($agent);

                $turnStarted = microtime(true);
                $reply = $agent->handleUserMessage((string) $turn['say']);
                // The same call controllers/chat.php makes, before any approval round. Recorded for every scenario, so
                // false notices show up everywhere.
                $notice = $agent->getTurnNotice();
                // P3.6-1: a paused turn is answered as the user would, through the panel's path (ConcreteCmsAgent::decide()).
                [$reply, $agent, $approvals] = $this->answerApprovals($agent, $chatId, $page, $reply, $turn['approval'] ?? 'approve');
                $notice ??= $agent->getTurnNotice();
                $turnSeconds = microtime(true) - $turnStarted;

                $after = $this->snapshots->take();
                $newMessages = $this->newMessages($agent, $idsBefore);
                $calls = $this->toolCalls($newMessages);
                $turnUsage = $this->usage($newMessages);
                $logErrors = $this->errorLogs($logMark);
                // BF-22: a provider timeout has its own reply, and is an error run too.
                $agentError = in_array($reply, [self::AGENT_ERROR_REPLY, ConcreteCmsAgent::TIMEOUT_REPLY], true);

                // BF-12: the run so far, this turn included, for no_invented_hosts and the recorded inventedHosts.
                $runCalls = array_merge($allCalls, $calls);
                $says[] = (string) $turn['say'];
                $inventedHosts = Hosts::invented($reply, $runCalls, $says);

                $turnChecks = [];
                if (isset($turn['expect'])) {
                    $context = new CheckContext($before, $start, $after, $calls, $reply, (string) $turn['say'], $notice, $runCalls, $says, $approvals);
                    foreach (Expectations::evaluate($turn['expect'], $context) as $check) {
                        $turnChecks[] = $check->at('turn ' . ($i + 1));
                    }
                }

                $checks = array_merge($checks, $turnChecks);
                $allCalls = $runCalls;
                $allApprovals = array_merge($allApprovals, $approvals);
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
                    // Recorded for every scenario, scored only where a scenario has no_invented_hosts.
                    'inventedHosts' => $inventedHosts,
                    'durationSec' => round($turnSeconds, 1),
                    'usage' => $turnUsage,
                    'toolCalls' => array_map(fn (array $call): array => $this->truncateCall($call), $calls),
                    // P3.6-1: each call the turn waited on: the tool, what the runner answered, and the outcome.
                    'approvals' => array_map(static fn (array $a): array => array_diff_key($a, ['callId' => true]), $approvals),
                    'logErrors' => $logErrors,
                    'checks' => array_map(static fn (CheckResult $c): array => $c->toArray(), $turnChecks),
                ];
            }

            if (isset($scenario['expect'])) {
                $context = new CheckContext($start, $start, $after, $allCalls, $lastReply, $lastSay, $anyNotice, $allCalls, $says, $allApprovals);
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

        // P3.11-1: an injection scenario names a piece of its injected text; a run that never read it is inconclusive.
        $exposed = true;
        if (isset($scenario['inconclusive_unless_read'])) {
            $exposed = Expectations::exposed((string) $scenario['inconclusive_unless_read'], $allCalls);
            $run['exposed'] = $exposed;
        }

        $run['usage'] = $usage;
        $run['checks'] = array_map(static fn (CheckResult $c): array => $c->toArray(), array_filter($checks, static fn (CheckResult $c): bool => $c->where === 'end'));
        $run['outcome'] = Expectations::outcome($checks, $hadError, $exposed);
        $run['durationSec'] = round(microtime(true) - $started, 1);

        return $run;
    }

    /**
     * P3.6-1: while the turn waits for approval, answers every waiting call with the turn's `approval` (approve by
     * default, tests/Scenarios/README.md; per tool since P3.6-3, ApprovalAnswer), each answer as a new request with a
     * new agent, as the panel's buttons are. At most MAX_APPROVAL_ROUNDS pauses per turn.
     *
     * @return array{string, ConcreteCmsAgent, list<array{tool: string, callId: string, decision: string, status: string}>}
     *     the turn's final reply, the agent that ended it, and the calls it waited on
     */
    private function answerApprovals(ConcreteCmsAgent $agent, int $chatId, string $page, string $reply, mixed $answer): array
    {
        $approvals = [];
        for ($round = 0; $agent->isAwaitingApproval() && $round < self::MAX_APPROVAL_ROUNDS; $round++) {
            $cards = $agent->getApprovalCards();
            if ($cards === []) {
                break;
            }
            foreach ($cards as $card) {
                if ($card['status'] !== ApprovalService::PENDING) {
                    continue;
                }
                $this->entityManager()->clear();
                $agent = ($this->agentFactory)();
                $agent->setChatId($chatId);
                $this->setPageContext($agent, $page);
                // P3.6-3: the answer may differ by tool (ApprovalAnswer).
                $decision = ApprovalAnswer::for($answer, (string) $card['tool']);
                $result = $agent->decide((string) $card['callId'], $decision === 'approve');
                // P3.6-3: the page each card named, for approvals_cover_children (scenario 17).
                // P3.6a: and what the card said (its action, warnings and details), for approval_card_matches.
                $approvals[] = ['tool' => (string) $card['tool'], 'callId' => (string) $card['callId'], 'decision' => $decision, 'status' => '', 'path' => (string) ($card['path'] ?? ''),
                    'text' => implode("\n", [(string) ($card['action'] ?? '') . ' ' . (string) ($card['target'] ?? ''), ...(array) ($card['warnings'] ?? []), ...(array) ($card['details'] ?? [])])];
                if ($result['reply'] !== null) {
                    $reply = $result['reply'];
                }
            }
        }
        if ($agent->isAwaitingApproval()) {
            // P3.6-2: still waiting after MAX_APPROVAL_ROUNDS pauses. The run is ended, as clearing the chat ends it, so
            // it isn't left in the workflow store when the chat is deleted; the turn's reply stays the waiting one.
            $agent->discardRun();
        }

        $statuses = ApprovalService::make()->statuses($chatId, array_column($approvals, 'callId'));
        foreach ($approvals as $i => $approval) {
            $approvals[$i]['status'] = $statuses[$approval['callId']] ?? '';
        }

        return [$reply, $agent, $approvals];
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
        $this->app->make('cache/request')->flush();

        // P3.2-1: the agent offers a user only the tools their tier allows, so a user with no tier would be offered
        // none and every run would fail for a reason the scenario isn't about.
        if (NeuronPermissions::tier() < 1) {
            throw new \RuntimeException("The user \"{$who}\" holds no Neuron AI tier key, so the assistant would offer no tools. Scenarios run as neuron_content_editor (run the seed) or superuser.");
        }

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
        // The same lookup as the chat endpoint, so a scenario can start on the home page ("/", BF-1).
        $page = PageContextResolver::pageForPath($path);
        if ($page === null) {
            throw new \RuntimeException("The scenario's current page \"{$path}\" does not exist.");
        }

        $context = [
            'id' => (int) $page->getCollectionID(),
            'path' => (string) $page->getCollectionPath() !== '' ? (string) $page->getCollectionPath() : '/',
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
     * The chat's active messages, read back from its rows (Neuron 4's getChatHistory(), P2.6).
     *
     * @return list<Message>
     */
    private function messages(ConcreteCmsAgent $agent): array
    {
        return array_values($agent->getChatHistory()->getMessages());
    }

    /**
     * @return array<string, true> the IDs of the chat's messages before a turn
     */
    private function messageIds(ConcreteCmsAgent $agent): array
    {
        return array_fill_keys(array_map(static fn (Message $m): string => $m->getId(), $this->messages($agent)), true);
    }

    /**
     * The turn's messages: those whose IDs were not there before it. Not "the ones after the old count": Neuron 4
     * archives the oldest messages when it trims, and the store's repair adds placeholders, so counts shift.
     *
     * @param array<string, true> $idsBefore
     * @return list<Message>
     */
    private function newMessages(ConcreteCmsAgent $agent, array $idsBefore): array
    {
        return array_values(array_filter($this->messages($agent), static fn (Message $m): bool => !isset($idsBefore[$m->getId()])));
    }

    /**
     * On Neuron 4 a call's result is not on the ToolCallMessage: it is on the ToolResultMessage that follows, matched
     * by call ID. A ToolOutput result is recorded as its JSON, which ToolKind reads (P2.3).
     *
     * @param list<Message> $messages
     *
     * @return list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool, callId: string}>
     */
    private function toolCalls(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }
            foreach ($message->getToolCalls() as $settled) {
                if ($settled->hasResult()) {
                    $value = $settled->getResult();
                    $results[(string) $settled->getCallId()] = $value instanceof ToolOutput ? (string) json_encode($value) : $value;
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
                    'callId' => (string) $tool->getCallId(),
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
