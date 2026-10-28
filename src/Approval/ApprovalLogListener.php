<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval;

use Concrete\Core\Support\Facade\Log;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Tools\ApprovalState;

/**
 * Logs every tool call and every approval decision to Concrete's log (P3.6-1 §6; also P3.9's PSR-14 logging listener).
 * A listener on Neuron's ToolCalled, held by the agent, and called by the agent for each approval decision. A line
 * names the tool, the call ID, the outcome and the decision; never the inputs or the result, and every value goes
 * through SecretRedactor.
 */
final class ApprovalLogListener
{
    private const PREFIX = 'Katalysis Neuron AI: ';

    public function __invoke(ToolCalled $event): void
    {
        $call = $event->tool;
        $outcome = !$call->hasResult() ? 'no result' : (ToolKind::succeeded($call->getResult()) ? 'ok' : 'failed');
        $approval = $call->getApprovalState();

        self::info(sprintf(
            'tool %s, call %s: %s%s',
            $call->getName(),
            (string) $call->getCallId(),
            $outcome,
            $approval instanceof ApprovalState ? ', approval ' . $approval->value : ''
        ));
    }

    /**
     * One approval decision, as the user (or a new message) made it.
     */
    public function decided(int $chatId, string $tool, string $callId, string $status, int $userId): void
    {
        self::info(sprintf('approval for tool %s, call %s in chat %d: %s by user %d', $tool, $callId, $chatId, $status, $userId));
    }

    private static function info(string $line): void
    {
        try {
            Log::info(self::PREFIX . SecretRedactor::redact($line));
        } catch (\Throwable) {
            // Logging must never fail a turn.
        }
    }
}
