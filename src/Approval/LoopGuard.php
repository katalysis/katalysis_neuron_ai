<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval;

use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Tools\ToolOutput;

/**
 * The loop guard (P3.9-3, B-ERR-3), for one turn: one run of the agent, from the user's message to the reply, across
 * the requests that approve or deny its calls. What it remembers lives in Neuron's run state (ApprovalToolNode), so it
 * survives a pause for approval and ends with the turn.
 *
 * - **A Deny stops the tool** for the rest of the turn, whatever its inputs (Glyn, after scenario 35 in P3.6-2: three
 *   create_page cards after three Denies, despite Neuron's "do not attempt this tool again").
 * - **A failed call isn't repeated** with the same inputs: it would fail again.
 * - **After one alternative,** a tool that has failed twice isn't run again: the model reports and stops.
 *
 * A refused call never runs and gets no card. Its result starts "TOOL NOT EXECUTED", so it counts as not done
 * (ToolKind::succeeded()) on the turn's Changed line. Pure functions over the remembered state, so it is unit-tested
 * without Concrete.
 */
final class LoopGuard
{
    /** Run-state keys (AgentState::set()). */
    public const DENIED_KEY = 'katalysis.loop_guard.denied';
    public const FAILED_KEY = 'katalysis.loop_guard.failed';

    /** How many failures of one tool end its use in the turn: the first attempt and one alternative. */
    public const MAX_FAILURES = 2;

    /**
     * Why the call must not run, or null.
     *
     * @param array<string, true> $denied tools the user denied in this turn
     * @param array<string, array<string, true>> $failed by tool, the inputs it failed with in this turn
     */
    public static function refusal(string $tool, array $inputs, array $denied, array $failed): ?string
    {
        if (isset($denied[$tool])) {
            return "TOOL NOT EXECUTED. The user declined {$tool} earlier in this request, so {$tool} isn't used again until "
                . "the user's next message, whatever its inputs. Don't try another way to make the same change. Ask the user "
                . 'what they want instead.';
        }
        if (isset($failed[$tool][self::key($inputs)])) {
            return "TOOL NOT EXECUTED. {$tool} already failed with these same inputs in this request, so it would fail "
                . 'again. Change what made it fail, or tell the user what failed.';
        }
        if (count($failed[$tool] ?? []) >= self::MAX_FAILURES) {
            return "TOOL NOT EXECUTED. {$tool} has failed twice in this request (the first attempt and one alternative). "
                . "Stop trying: tell the user what failed and why, and ask what they want to do.";
        }

        return null;
    }

    /**
     * The denied tools after a decision: the user's Deny adds the tool. A rejection that isn't the user's Deny (stale,
     * a new message, a call its own checks refused) doesn't.
     *
     * @param array<string, true> $denied
     * @return array<string, true>
     */
    public static function afterDecision(string $tool, ?string $rejectReason, array $denied): array
    {
        if ($rejectReason !== null && str_starts_with($rejectReason, ApprovalService::DENY_PREFIX)) {
            $denied[$tool] = true;
        }

        return $denied;
    }

    /**
     * The failures after a call settled: a call that didn't succeed is remembered with its inputs.
     *
     * @param array<string, array<string, true>> $failed
     * @return array<string, array<string, true>>
     */
    public static function afterResult(string $tool, array $inputs, string|ToolOutput $result, array $failed): array
    {
        $text = $result instanceof ToolOutput ? $result->getText() : $result;
        if (!ToolKind::succeeded($result) || str_starts_with(ltrim($text), '❌')) {
            $failed[$tool][self::key($inputs)] = true;
        }

        return $failed;
    }

    /** The inputs as one comparable key: keys sorted at every level, so the order the model wrote them in doesn't count. */
    public static function key(array $inputs): string
    {
        $sort = static function (array $value) use (&$sort): array {
            ksort($value);
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $value[$k] = $sort($v);
                }
            }

            return $value;
        };

        return sha1((string) json_encode($sort($inputs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
