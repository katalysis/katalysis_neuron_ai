<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * Turns the results of a run into a markdown table and a JSON file.
 *
 * $results = [
 *   'meta'      => [startedAt, repeat, filter, model, commit, neuronVersion, concreteVersion, phpVersion, durationSec],
 *   'scenarios' => [id => ['id', 'title', 'knownToFailToday' => ?string, 'runs' => list<run>]],
 * ]
 * and a run is what ScenarioRunner::run() returns.
 *
 * The JSON has the same content, with a "schema" number, so a later `--compare` can read it
 * without parsing the markdown.
 */
final class ResultsWriter
{
    public const SCHEMA = 1;
    private const ORDER = [Expectations::PASS, Expectations::PARTIAL, Expectations::FAIL, Expectations::ERROR];

    /**
     * List prices in USD per 1M tokens [input, output], used only for an approximate cost line. They are an
     * assumption written into the code, not read from the provider: check them against the provider's price page.
     * The estimate ignores cached-input discounts, so it is an upper bound.
     */
    private const PRICES = ['gpt-4o' => [2.50, 10.00]];

    /**
     * "pass" for one run, or "2/3 pass, 1/3 partial" for several.
     *
     * @param list<array<string, mixed>> $runs
     */
    public static function outcomeLabel(array $runs): string
    {
        if (count($runs) === 1) {
            return (string) $runs[0]['outcome'];
        }

        $counts = array_count_values(array_column($runs, 'outcome'));
        $parts = [];
        foreach (self::ORDER as $outcome) {
            if (!empty($counts[$outcome])) {
                $parts[] = $counts[$outcome] . '/' . count($runs) . ' ' . $outcome;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $runs
     *
     * @return array{input: int, output: int}|null null when no run reported any usage
     */
    public static function usageOf(array $runs): ?array
    {
        $total = null;
        foreach ($runs as $run) {
            if (!is_array($run['usage'] ?? null)) {
                continue;
            }
            $total ??= ['input' => 0, 'output' => 0];
            $total['input'] += (int) $run['usage']['input'];
            $total['output'] += (int) $run['usage']['output'];
        }

        return $total;
    }

    /**
     * @param array{input: int, output: int}|null $usage
     */
    public static function usageLabel(?array $usage): string
    {
        return $usage === null ? 'not reported' : number_format($usage['input']) . ' / ' . number_format($usage['output']);
    }

    /**
     * Approximate cost in USD, or null when the model has no price in PRICES or no usage was reported.
     *
     * @param array{input: int, output: int}|null $usage
     * @param string                              $model e.g. "openai gpt-4o" (the part after the provider name is the model id)
     */
    public static function costOf(?array $usage, string $model): ?float
    {
        $id = trim((string) preg_replace('/^\S+\s+/', '', $model));
        if ($usage === null || !isset(self::PRICES[$id])) {
            return null;
        }

        return ($usage['input'] * self::PRICES[$id][0] + $usage['output'] * self::PRICES[$id][1]) / 1_000_000;
    }

    public static function costLabel(?float $cost): string
    {
        return $cost === null ? 'not estimated' : 'about $' . number_format($cost, 2);
    }

    /**
     * @param array<string, mixed> $results
     */
    public static function toJson(array $results): string
    {
        $results['schema'] = self::SCHEMA;
        $allRuns = self::allRuns($results);
        $results['totals'] = [
            'runs' => count($allRuns),
            'outcomes' => array_count_values(array_column($allRuns, 'outcome')),
            'usage' => self::usageOf($allRuns),
            'approxCostUsd' => self::costOf(self::usageOf($allRuns), (string) $results['meta']['model']),
        ];
        foreach ($results['scenarios'] as &$scenario) {
            $scenario['summary'] = self::outcomeLabel($scenario['runs']);
            $scenario['usage'] = self::usageOf($scenario['runs']);
        }
        unset($scenario);

        return (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /**
     * @param array<string, mixed> $results
     */
    public static function toMarkdown(array $results): string
    {
        $meta = $results['meta'];
        $allRuns = self::allRuns($results);
        $totalUsage = self::usageOf($allRuns);

        $md = "# Neuron AI scenario results\n\n";
        $md .= '- Run: ' . $meta['startedAt'] . ' (' . $meta['durationSec'] . " s)\n";
        $md .= '- Scenarios: ' . count($results['scenarios']) . ', repeat ' . $meta['repeat'] . ' (' . count($allRuns) . ' runs)'
            . ($meta['filter'] !== null && $meta['filter'] !== '' ? ', filter `' . $meta['filter'] . '`' : '') . "\n";
        $md .= '- Model: ' . $meta['model'] . ', temperature: ' . ($meta['temperature'] ?? 'not recorded') . "\n";
        $md .= '- Code: ' . $meta['commit'] . '; neuron-core/neuron-ai ' . $meta['neuronVersion'] . '; Concrete ' . $meta['concreteVersion'] . '; PHP ' . $meta['phpVersion'] . "\n";
        $md .= "- Write tools are recognised by name: a tool whose name doesn't start with get_, list_, search_, find_, count_, describe_, read_, lookup_, check_, validate_, preview_, inspect_, export_ or resolve_ counts as a write.\n\n";

        $md .= "| Scenario | Result | Tokens in / out | Time (s) | Expected to fail |\n";
        $md .= "|---|---|---|---|---|\n";
        foreach ($results['scenarios'] as $scenario) {
            $runs = $scenario['runs'];
            $md .= sprintf(
                "| %s | %s | %s | %s | %s |\n",
                self::cell($scenario['id']),
                self::cell(self::outcomeLabel($runs)),
                self::usageLabel(self::usageOf($runs)),
                number_format(array_sum(array_column($runs, 'durationSec')) / max(1, count($runs)), 1),
                $scenario['knownToFailToday'] !== null ? 'yes' : ''
            );
        }

        $outcomes = array_count_values(array_column($allRuns, 'outcome'));
        $summary = [];
        foreach (self::ORDER as $outcome) {
            if (!empty($outcomes[$outcome])) {
                $summary[] = $outcomes[$outcome] . ' ' . $outcome;
            }
        }
        $md .= "\n**Total:** " . implode(', ', $summary) . ' of ' . count($allRuns) . " runs.\n";
        $md .= '**Tokens (in / out), all runs:** ' . self::usageLabel($totalUsage) . ($totalUsage === null ? ' (the provider returned no usage data on any message)' : '') . ".\n";
        $md .= '**Approximate cost:** ' . self::costLabel(self::costOf($totalUsage, (string) $meta['model'])) . " (assumed list price, no cached-input discount; an upper bound).\n";
        $md .= "Time per scenario is the average of its runs. \"Expected to fail\" is the scenario's `known_to_fail_today` note from P1.2, which P1.5 confirms or corrects.\n";

        $hosts = self::inventedHosts($results);
        if ($hosts !== '') {
            $md .= "\n## Hosts no tool returned (BF-12)\n\nRecorded for every run, whatever its outcome; scored only where a scenario has `no_invented_hosts`.\n\n" . $hosts;
        }

        $details = self::details($results);
        if ($details !== '') {
            $md .= "\n## Details of runs that did not pass\n\n" . $details;
        }

        return $md;
    }

    /**
     * Writes <dir>/<timestamp>.md and <dir>/<timestamp>.json.
     *
     * @param array<string, mixed> $results
     *
     * @return array{md: string, json: string} the file paths
     */
    public static function write(string $dir, string $timestamp, array $results): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }

        $paths = ['md' => $dir . '/' . $timestamp . '.md', 'json' => $dir . '/' . $timestamp . '.json'];
        file_put_contents($paths['md'], self::toMarkdown($results));
        file_put_contents($paths['json'], self::toJson($results));

        return $paths;
    }

    /**
     * @param array<string, mixed> $results
     *
     * @return list<array<string, mixed>>
     */
    private static function allRuns(array $results): array
    {
        $runs = [];
        foreach ($results['scenarios'] as $scenario) {
            array_push($runs, ...$scenario['runs']);
        }

        return $runs;
    }

    /**
     * @param array<string, mixed> $results
     */
    private static function inventedHosts(array $results): string
    {
        $md = '';
        foreach ($results['scenarios'] as $scenario) {
            foreach ($scenario['runs'] as $run) {
                foreach ($run['turns'] ?? [] as $i => $turn) {
                    if (!empty($turn['inventedHosts'])) {
                        $md .= '- ' . $scenario['id'] . ' (run ' . $run['n'] . ', turn ' . ($i + 1) . '): ' . self::inline(implode(', ', $turn['inventedHosts'])) . "\n";
                    }
                }
            }
        }

        return $md;
    }

    /**
     * @param array<string, mixed> $results
     */
    private static function details(array $results): string
    {
        $md = '';
        foreach ($results['scenarios'] as $scenario) {
            foreach ($scenario['runs'] as $run) {
                if ($run['outcome'] === Expectations::PASS) {
                    continue;
                }

                $md .= '### ' . $scenario['id'] . ' (run ' . $run['n'] . '): ' . $run['outcome'] . "\n\n";
                if (!empty($run['error'])) {
                    $md .= '- Runner error: ' . self::inline((string) $run['error']) . "\n";
                }

                foreach ($run['turns'] as $i => $turn) {
                    $md .= '- Turn ' . ($i + 1) . ' asked: ' . self::excerpt((string) ($turn['say'] ?? ''), 200) . "\n";
                    foreach ($turn['toolCalls'] ?? [] as $call) {
                        $md .= '  - tool `' . $call['name'] . '` (' . (!empty($call['write']) ? 'write' : 'read') . ', ' . (!empty($call['ok']) ? 'ok' : 'failed') . '): '
                            . self::excerpt((string) $call['inputs'], 200) . ' -> ' . self::excerpt((string) $call['result'], 200) . "\n";
                    }
                    $md .= '  - reply: ' . self::excerpt((string) ($turn['reply'] ?? ''), 500) . "\n";
                    if (!empty($turn['agentError'])) {
                        $md .= '- Turn ' . ($i + 1) . ": the agent returned its generic error reply.\n";
                    }
                    foreach ($turn['logErrors'] as $log) {
                        $md .= '- Turn ' . ($i + 1) . ' logged error: ' . self::inline($log) . "\n";
                    }
                    foreach ($turn['checks'] as $check) {
                        $md .= self::failedCheck($check);
                    }
                }
                foreach ($run['checks'] as $check) {
                    $md .= self::failedCheck($check);
                }
                $md .= "\n";
            }
        }

        return $md;
    }

    /**
     * @param array<string, mixed> $check
     */
    private static function failedCheck(array $check): string
    {
        if ($check['passed']) {
            return '';
        }

        $kind = $check['group'] === CheckResult::REPLY ? ($check['primary'] ? 'primary reply' : 'secondary reply') : $check['group'];

        return '- ' . $check['where'] . ', ' . $kind . ' check `' . self::inline((string) $check['name']) . '` failed' . ($check['detail'] !== '' ? ': ' . self::inline((string) $check['detail']) : '') . "\n";
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }

    private static function inline(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private static function excerpt(string $text, int $max): string
    {
        $text = self::inline($text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '...' : $text;
    }
}
