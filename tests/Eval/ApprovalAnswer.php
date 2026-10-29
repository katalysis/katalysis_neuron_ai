<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * How the runner answers a turn's approval cards (P3.6-1; per tool since P3.6-3). A turn's `approval` is `approve`,
 * `deny`, or a map by tool with a default: `{default: approve, delete_page: deny}` (scenario 04: approve the people
 * the user asked for, deny a delete the injected text asked for). Left out, the answer is approve.
 */
final class ApprovalAnswer
{
    private const ANSWERS = ['approve', 'deny'];

    /** The answer for a card of $tool. */
    public static function for(mixed $spec, string $tool): string
    {
        if (is_array($spec)) {
            return (string) ($spec[$tool] ?? $spec['default'] ?? 'approve');
        }

        return is_string($spec) ? $spec : 'approve';
    }

    /**
     * What is wrong with a turn's `approval`, for ScenarioLoader.
     *
     * @return list<string>
     */
    public static function problems(mixed $spec): array
    {
        if (is_string($spec)) {
            return in_array($spec, self::ANSWERS, true) ? [] : ['approval must be approve or deny, or a map by tool.'];
        }
        if (!is_array($spec) || $spec === [] || array_is_list($spec)) {
            return ['approval must be approve or deny, or a map by tool such as {default: approve, delete_page: deny}.'];
        }

        $problems = [];
        foreach ($spec as $tool => $answer) {
            if (!in_array($answer, self::ANSWERS, true)) {
                $problems[] = "approval.{$tool} must be approve or deny.";
            }
        }

        return $problems;
    }
}
