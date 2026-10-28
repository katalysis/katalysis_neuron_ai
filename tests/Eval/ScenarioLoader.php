<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads tests/Scenarios/*.yaml and checks every key against the vocabulary in
 * tests/Scenarios/README.md, so a typo in a check name fails loudly instead of
 * silently checking nothing.
 */
final class ScenarioLoader
{
    private const TOP_KEYS = ['id', 'title', 'tier', 'user', 'current_page', 'rules', 'known_to_fail_today', 'rubric', 'setup', 'turns', 'expect', 'inconclusive_unless_read'];
    private const TURN_KEYS = ['say', 'approval', 'current_page', 'expect'];
    private const EXPECT_KEYS = ['state', 'tools', 'reply'];
    private const STATE_KEYS = [
        'nothing_changed', 'page_exists', 'page_not_published', 'page_absent', 'page_unchanged', 'page_type', 'attribute',
        'attribute_on_children_of', 'blocks', 'all_text_unchanged', 'live_version_unchanged', 'file_exists',
        'people', 'person_field_empty', 'changed_pages', 'users_unchanged',
    ];
    private const BLOCK_KEYS = ['page', 'area', 'count', 'headings', 'contains_html', 'lacks_html', 'unchanged', 'has_block', 'has_block_unchanged', 'has_block_field'];
    private const TOOL_KEYS = ['no_write_tool_succeeded', 'no_write_tool_failed', 'not_succeeded', 'not_called', 'called_any', 'succeeded', 'notice_shown', 'notice_absent'];
    private const REPLY_KEYS = ['matches_all', 'matches_any', 'not_matches', 'shorter_than_prompt', 'lists_recorded_children', 'no_invented_hosts', 'describes_page_only_if_read', 'no_untrusted_links'];
    // P3.2-1: neuron_content_editor holds the Tier 1 key. neuron_editor holds none (the no-access fixture), so the
    // runner refuses it: kept here so the loader still reads an older scenario and the runner says why.
    private const USERS = ['neuron_content_editor', 'neuron_editor', 'superuser'];

    /**
     * @return array<string, array<string, mixed>> scenarios keyed by id, in file-name order
     */
    public static function loadAll(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.yaml') ?: [];
        sort($files);

        $scenarios = [];
        $problems = [];
        foreach ($files as $file) {
            try {
                $scenario = self::loadFile($file);
                $scenarios[$scenario['id']] = $scenario;
            } catch (\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }

        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }

        return $scenarios;
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadFile(string $file): array
    {
        $data = Yaml::parse((string) file_get_contents($file));
        $errors = self::validate($data, basename($file, '.yaml'));
        if ($errors !== []) {
            throw new \InvalidArgumentException(basename($file) . ":\n  - " . implode("\n  - ", $errors));
        }

        return $data;
    }

    /**
     * Keeps the scenarios whose id equals a filter, or starts with it followed by "-" (so "05" selects "05-h3-to-h2").
     * Several filters can be given, comma-separated.
     *
     * @param array<string, array<string, mixed>> $scenarios
     *
     * @return array<string, array<string, mixed>>
     */
    public static function filter(array $scenarios, ?string $filter): array
    {
        if ($filter === null || trim($filter) === '') {
            return $scenarios;
        }

        $wanted = array_filter(array_map('trim', explode(',', $filter)), static fn (string $f): bool => $f !== '');

        return array_filter($scenarios, static function (array $scenario) use ($wanted): bool {
            foreach ($wanted as $w) {
                if ($scenario['id'] === $w || str_starts_with($scenario['id'], $w . '-')) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param mixed $data
     *
     * @return list<string> problems; empty when the scenario is valid
     */
    public static function validate($data, string $expectedId): array
    {
        if (!is_array($data)) {
            return ['the file is not a YAML mapping.'];
        }

        $errors = [];
        foreach (array_diff(array_keys($data), self::TOP_KEYS) as $key) {
            $errors[] = "unknown key \"{$key}\".";
        }
        foreach (['id', 'title', 'user', 'current_page', 'turns'] as $key) {
            if (!array_key_exists($key, $data)) {
                $errors[] = "missing key \"{$key}\".";
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        if ($data['id'] !== $expectedId) {
            $errors[] = "id \"{$data['id']}\" does not match the file name \"{$expectedId}\".";
        }
        if (!in_array($data['user'], self::USERS, true)) {
            $errors[] = 'user must be one of: ' . implode(', ', self::USERS) . '.';
        }
        if (!is_string($data['current_page']) || !str_starts_with($data['current_page'], '/')) {
            $errors[] = 'current_page must be a path starting with "/".';
        }
        if (!is_array($data['turns']) || $data['turns'] === [] || !array_is_list($data['turns'])) {
            return array_merge($errors, ['turns must be a non-empty list.']);
        }

        if (array_key_exists('inconclusive_unless_read', $data)
            && (!is_string($data['inconclusive_unless_read']) || trim($data['inconclusive_unless_read']) === '')) {
            $errors[] = 'inconclusive_unless_read must be a piece of the injected text.';
        }

        if (array_key_exists('setup', $data)) {
            $errors = array_merge($errors, ScenarioSetup::validate($data['setup']));
        }

        foreach ($data['turns'] as $i => $turn) {
            $label = 'turn ' . ($i + 1);
            if (!is_array($turn)) {
                $errors[] = "{$label} is not a mapping.";
                continue;
            }
            foreach (array_diff(array_keys($turn), self::TURN_KEYS) as $key) {
                $errors[] = "{$label}: unknown key \"{$key}\".";
            }
            if (!isset($turn['say']) || !is_string($turn['say']) || trim($turn['say']) === '') {
                $errors[] = "{$label}: say must be a non-empty string.";
            }
            if (isset($turn['approval']) && !in_array($turn['approval'], ['approve', 'deny'], true)) {
                $errors[] = "{$label}: approval must be approve or deny.";
            }
            if (isset($turn['expect'])) {
                $errors = array_merge($errors, self::validateExpect($turn['expect'], $label));
            }
        }
        if (isset($data['expect'])) {
            $errors = array_merge($errors, self::validateExpect($data['expect'], 'expect'));
        }

        return $errors;
    }

    /**
     * @param mixed $expect
     *
     * @return list<string>
     */
    private static function validateExpect($expect, string $label): array
    {
        if (!is_array($expect)) {
            return ["{$label}: expect is not a mapping."];
        }

        $errors = [];
        foreach (array_diff(array_keys($expect), self::EXPECT_KEYS) as $key) {
            $errors[] = "{$label}: unknown expect group \"{$key}\".";
        }

        foreach (self::checkKeyProblems($expect['state'] ?? [], self::STATE_KEYS, "{$label}.state") as $e) {
            $errors[] = $e;
        }
        foreach ($expect['state']['blocks'] ?? [] as $i => $block) {
            foreach (self::checkKeyProblems($block, self::BLOCK_KEYS, "{$label}.state.blocks[{$i}]") as $e) {
                $errors[] = $e;
            }
            if (!isset($block['page'])) {
                $errors[] = "{$label}.state.blocks[{$i}]: missing \"page\".";
            }
        }
        foreach (self::checkKeyProblems($expect['tools'] ?? [], self::TOOL_KEYS, "{$label}.tools") as $e) {
            $errors[] = $e;
        }
        foreach (self::checkKeyProblems($expect['reply'] ?? [], self::REPLY_KEYS, "{$label}.reply") as $e) {
            $errors[] = $e;
        }

        // One page as a mapping, or several as a list of mappings (P3.11-1); tool may be left out (any read tool).
        $describes = $expect['reply']['describes_page_only_if_read'] ?? null;
        $describesSpecs = is_array($describes) && array_is_list($describes) ? $describes : ($describes === null ? [] : [$describes]);
        foreach ($describesSpecs as $spec) {
            if (!is_array($spec) || !is_string($spec['page'] ?? null) || !str_starts_with($spec['page'], '/')
                || (array_key_exists('tool', $spec) && (!is_string($spec['tool']) || $spec['tool'] === ''))
                || !is_array($spec['patterns'] ?? null) || $spec['patterns'] === []) {
                $errors[] = "{$label}.reply: describes_page_only_if_read needs page (a path), tool and patterns (tool may be left out: any read tool).";
            }
        }

        $groups = [];
        foreach (['matches_all', 'matches_any', 'not_matches'] as $group) {
            if (isset($expect['reply'][$group])) {
                $groups[] = [$group, $expect['reply'][$group]];
            }
        }
        foreach ($describesSpecs as $spec) {
            if (is_array($spec) && is_array($spec['patterns'] ?? null)) {
                $groups[] = ['describes_page_only_if_read', $spec];
            }
        }
        foreach ($groups as [$group, $groupSpec]) {
            [$patterns] = ReplyChecks::patternGroup($groupSpec);
            foreach ($patterns as $pattern) {
                if (!is_string($pattern) || @preg_match(ReplyChecks::regex($pattern), '') === false) {
                    $errors[] = "{$label}.reply.{$group}: not a valid regular expression: " . json_encode($pattern);
                }
            }
        }

        return $errors;
    }

    /**
     * @param mixed $group
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function checkKeyProblems($group, array $allowed, string $label): array
    {
        if (!is_array($group)) {
            return ["{$label} is not a mapping."];
        }

        $errors = [];
        foreach (array_diff(array_keys($group), $allowed) as $key) {
            $errors[] = "{$label}: unknown check \"{$key}\".";
        }

        return $errors;
    }
}
