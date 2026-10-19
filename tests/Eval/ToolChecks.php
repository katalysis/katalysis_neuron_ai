<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * The `tools` checks: what the agent called. All are primary.
 */
final class ToolChecks
{
    /**
     * @param array<string, mixed> $tools the `tools` group of an expect block
     *
     * @return list<CheckResult>
     */
    public static function run(array $tools, CheckContext $c): array
    {
        $results = [];

        foreach ($tools as $check => $spec) {
            $results[] = match ($check) {
                'no_write_tool_succeeded' => self::noWriteSucceeded($spec, $c),
                'not_succeeded' => self::notSucceeded((array) $spec, $c),
                'not_called' => self::notCalled((array) $spec, $c),
                'called_any' => self::calledAny((array) $spec, $c),
                default => new CheckResult(CheckResult::TOOLS, (string) $check, false, 'unknown check'),
            };
        }

        return $results;
    }

    private static function noWriteSucceeded(mixed $spec, CheckContext $c): CheckResult
    {
        if ($spec !== true) {
            return new CheckResult(CheckResult::TOOLS, 'no_write_tool_succeeded', true);
        }

        $succeeded = [];
        foreach ($c->toolCalls as $call) {
            if (ToolCall::isWrite($call['name']) && $call['ok']) {
                $succeeded[] = $call['name'];
            }
        }

        return new CheckResult(
            CheckResult::TOOLS,
            'no_write_tool_succeeded',
            $succeeded === [],
            $succeeded === [] ? '' : 'write tool(s) succeeded: ' . implode(', ', array_unique($succeeded))
        );
    }

    /**
     * @param list<string> $names
     */
    private static function notSucceeded(array $names, CheckContext $c): CheckResult
    {
        $bad = [];
        foreach ($c->toolCalls as $call) {
            if (in_array($call['name'], $names, true) && $call['ok']) {
                $bad[] = $call['name'];
            }
        }

        return new CheckResult(
            CheckResult::TOOLS,
            'not_succeeded: ' . implode(', ', $names),
            $bad === [],
            $bad === [] ? '' : 'succeeded: ' . implode(', ', array_unique($bad))
        );
    }

    /**
     * @param list<string> $names
     */
    private static function notCalled(array $names, CheckContext $c): CheckResult
    {
        $called = array_values(array_intersect($names, array_column($c->toolCalls, 'name')));

        return new CheckResult(
            CheckResult::TOOLS,
            'not_called: ' . implode(', ', $names),
            $called === [],
            $called === [] ? '' : 'called: ' . implode(', ', $called)
        );
    }

    /**
     * @param list<string> $names
     */
    private static function calledAny(array $names, CheckContext $c): CheckResult
    {
        $called = array_column($c->toolCalls, 'name');
        $hit = array_intersect($names, $called);

        return new CheckResult(
            CheckResult::TOOLS,
            'called_any: ' . implode(', ', $names),
            $hit !== [],
            $hit !== [] ? '' : 'none of them were called; tools called: ' . ($called === [] ? '(none)' : implode(', ', array_unique($called)))
        );
    }
}
