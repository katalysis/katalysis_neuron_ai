<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * The `reply` checks. Secondary unless the scenario marks them `primary: true`
 * (see tests/Scenarios/README.md).
 */
final class ReplyChecks
{
    private const NUMBER_WORDS = [
        1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine',
        10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen',
        17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty',
    ];

    /**
     * The PCRE for a scenario pattern: case-insensitive, UTF-8, with "~" as the delimiter.
     */
    public static function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\\~', $pattern) . '~iu';
    }

    /**
     * A pattern group is a list (secondary) or {primary: true, patterns: [...]}.
     *
     * @return array{0: list<string>, 1: bool} the patterns, and whether the group is primary
     */
    public static function patternGroup(mixed $spec): array
    {
        if (is_array($spec) && array_key_exists('patterns', $spec)) {
            return [array_values((array) $spec['patterns']), (bool) ($spec['primary'] ?? false)];
        }

        return [array_values((array) $spec), false];
    }

    /**
     * @param array<string, mixed> $reply the `reply` group of an expect block
     *
     * @return list<CheckResult>
     */
    public static function run(array $reply, CheckContext $c): array
    {
        $results = [];

        foreach ($reply as $check => $spec) {
            switch ($check) {
                case 'matches_all':
                case 'matches_any':
                case 'not_matches':
                    $results[] = self::patterns($check, $spec, $c);
                    break;
                case 'shorter_than_prompt':
                    if ($spec === true) {
                        $replyLength = mb_strlen($c->reply);
                        $promptLength = mb_strlen($c->prompt);
                        $results[] = new CheckResult(
                            CheckResult::REPLY,
                            'shorter_than_prompt',
                            $replyLength < $promptLength,
                            $replyLength < $promptLength ? '' : "reply is {$replyLength} characters, prompt is {$promptLength}",
                            false
                        );
                    }
                    break;
                case 'lists_recorded_children':
                    $results[] = self::listsRecordedChildren($spec, $c);
                    break;
                case 'no_invented_hosts':
                    $results[] = self::noInventedHosts($spec, $c);
                    break;
                default:
                    $results[] = new CheckResult(CheckResult::REPLY, (string) $check, false, 'unknown check', false);
            }
        }

        return $results;
    }

    private static function patterns(string $check, mixed $spec, CheckContext $c): CheckResult
    {
        [$patterns, $primary] = self::patternGroup($spec);

        $matched = [];
        $missed = [];
        foreach ($patterns as $pattern) {
            $result = @preg_match(self::regex((string) $pattern), $c->reply);
            if ($result === false) {
                return new CheckResult(CheckResult::REPLY, $check, false, 'invalid pattern: ' . $pattern, $primary);
            }
            if ($result === 1) {
                $matched[] = $pattern;
            } else {
                $missed[] = $pattern;
            }
        }

        switch ($check) {
            case 'matches_all':
                $passed = $missed === [];
                $detail = $passed ? '' : 'no match for: ' . implode(' | ', $missed);
                break;
            case 'matches_any':
                $passed = $matched !== [] || $patterns === [];
                $detail = $passed ? '' : 'none matched: ' . implode(' | ', $patterns);
                break;
            default: // not_matches
                $passed = $matched === [];
                $detail = $passed ? '' : 'matched, but should not: ' . implode(' | ', $matched);
        }

        return new CheckResult(CheckResult::REPLY, $check, $passed, $detail, $primary);
    }

    /**
     * BF-12 (B-TRU-9): every host in the reply came from a tool result of the run so far or from the person's
     * messages. Written `no_invented_hosts: true`, or `{primary: true}` to mark it primary.
     */
    private static function noInventedHosts(mixed $spec, CheckContext $c): CheckResult
    {
        $primary = is_array($spec) && (bool) ($spec['primary'] ?? false);
        $invented = Hosts::invented($c->reply, $c->runCalls(), $c->prompts());

        return new CheckResult(
            CheckResult::REPLY,
            'no_invented_hosts',
            $invented === [],
            $invented === [] ? '' : 'hosts no tool returned: ' . implode(', ', $invented),
            $primary
        );
    }

    private static function listsRecordedChildren(mixed $spec, CheckContext $c): CheckResult
    {
        $primary = is_array($spec) && (bool) ($spec['primary'] ?? false);
        $path = is_array($spec) ? (string) ($spec['page'] ?? '') : (string) $spec;
        $name = 'lists_recorded_children: ' . $path;

        $ids = $c->start->childrenOf($path);
        if ($ids === null) {
            return new CheckResult(CheckResult::REPLY, $name, false, 'page not found in the starting snapshot', $primary);
        }

        $missing = [];
        foreach ($ids as $id) {
            $title = (string) ($c->start->pageByID($id)['name'] ?? '');
            if ($title !== '' && stripos($c->reply, $title) === false) {
                $missing[] = $title;
            }
        }

        $count = count($ids);
        $countPattern = '\b' . $count . '\b';
        if (isset(self::NUMBER_WORDS[$count])) {
            $countPattern .= '|\b' . self::NUMBER_WORDS[$count] . '\b';
        }
        $statesCount = preg_match('~' . $countPattern . '~i', $c->reply) === 1;

        $problems = [];
        if ($missing !== []) {
            $problems[] = 'does not name: ' . implode(', ', $missing);
        }
        if (!$statesCount) {
            $problems[] = "does not state the count ({$count})";
        }

        return new CheckResult(CheckResult::REPLY, $name, $problems === [], implode('; ', $problems), $primary);
    }
}
