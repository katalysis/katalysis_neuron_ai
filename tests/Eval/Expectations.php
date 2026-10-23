<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * Runs an `expect` block (state, tools, reply) and turns the check results into a scenario outcome.
 */
final class Expectations
{
    public const PASS = 'pass';
    public const PARTIAL = 'partial';
    public const FAIL = 'fail';
    public const ERROR = 'error';

    /**
     * @param array<string, mixed> $expect
     *
     * @return list<CheckResult>
     */
    public static function evaluate(array $expect, CheckContext $c): array
    {
        $results = [];
        foreach (StateChecks::run($expect['state'] ?? [], $c) as $r) {
            $results[] = $r;
        }
        // Always, named or not (BF-7): the run must never save escaped or broken HTML.
        $results[] = StateChecks::savedHtmlValid($c);
        foreach (ToolChecks::run($expect['tools'] ?? [], $c) as $r) {
            $results[] = $r;
        }
        foreach (ReplyChecks::run($expect['reply'] ?? [], $c) as $r) {
            $results[] = $r;
        }

        return $results;
    }

    /**
     * As tests/Scenarios/README.md defines it:
     * - fail: any state or tools check failed, or any primary reply check failed
     * - partial: only secondary reply checks failed
     * - pass: everything passed
     *
     * "error" is not in the README. It is for a run that could not be judged: the agent returned its
     * generic error reply (the provider failed, or a tool crashed it) or the runner itself threw.
     * P1.5 must not record an error as a baseline result.
     *
     * @param list<CheckResult> $checks
     */
    public static function outcome(array $checks, bool $hadError): string
    {
        if ($hadError) {
            return self::ERROR;
        }

        $partial = false;
        foreach ($checks as $check) {
            if ($check->passed) {
                continue;
            }
            if ($check->group !== CheckResult::REPLY || $check->primary) {
                return self::FAIL;
            }
            $partial = true;
        }

        return $partial ? self::PARTIAL : self::PASS;
    }
}
