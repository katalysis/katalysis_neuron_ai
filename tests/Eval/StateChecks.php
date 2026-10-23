<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Katalysis\NeuronAi\Tools\Utilities\HtmlIntegrity;

/**
 * The `state` checks: what is in the CMS afterwards. All are primary.
 * Semantics are in tests/Scenarios/README.md. Pages are named by path and looked
 * up in the snapshots; nothing here uses an ID that a scenario wrote down.
 */
final class StateChecks
{
    public const TEST_PERSON_PREFIX = 'Test Person ';
    public const TEST_EMAIL_DOMAIN = '@neuron-test.invalid';

    /**
     * @param array<string, mixed> $state the `state` group of an expect block
     *
     * @return list<CheckResult>
     */
    public static function run(array $state, CheckContext $c): array
    {
        $results = [];

        foreach ($state as $check => $spec) {
            switch ($check) {
                case 'nothing_changed':
                    if ($spec === true) {
                        $diff = $c->after->describeDifferences($c->before);
                        $results[] = self::result('nothing_changed', $diff === [], implode('; ', $diff));
                    }
                    break;
                case 'page_exists':
                    $results[] = self::result("page_exists: {$spec}", $c->after->pageByPath((string) $spec) !== null, 'no page at that path');
                    break;
                case 'page_not_published':
                    $page = $c->after->pageByPath((string) $spec);
                    $results[] = self::result(
                        "page_not_published: {$spec}",
                        $page !== null && $page['approvedVersion'] === null,
                        $page === null ? 'no page at that path' : 'the page has an approved version, so visitors can see it'
                    );
                    break;
                case 'page_absent':
                    $results[] = self::result("page_absent: {$spec}", $c->after->pageByPath((string) $spec) === null, 'a page is still at that path');
                    break;
                case 'page_unchanged':
                    $results[] = self::pageUnchanged((string) $spec, $c);
                    break;
                case 'page_type':
                    $results[] = self::pageType($spec, $c);
                    break;
                case 'attribute':
                    $results[] = self::attribute($spec, $c);
                    break;
                case 'attribute_on_children_of':
                    $results[] = self::attributeOnChildren($spec, $c);
                    break;
                case 'blocks':
                    foreach ($spec as $entry) {
                        array_push($results, ...self::blocks($entry, $c));
                    }
                    break;
                case 'all_text_unchanged':
                    $results[] = self::allTextUnchanged((string) $spec, $c);
                    break;
                case 'live_version_unchanged':
                    $results[] = self::liveVersionUnchanged((string) $spec, $c);
                    break;
                case 'file_exists':
                    $results[] = self::fileExists($spec, $c);
                    break;
                case 'people':
                    array_push($results, ...self::people($spec, $c));
                    break;
                case 'person_field_empty':
                    $results[] = self::personFieldEmpty($spec, $c);
                    break;
                case 'changed_pages':
                    $results[] = self::changedPages($spec, $c);
                    break;
                default:
                    $results[] = self::result((string) $check, false, 'unknown check');
            }
        }

        return $results;
    }

    /**
     * saved_html_valid (BF-7, Glyn's browser check 6): every block string the run wrote, on any page under
     * /neuron-test, has no JSON escape sequences ("<\/", a literal "\r\n") and, if it is HTML, is well-formed.
     * Expectations runs it for every expect block, named or not. A string already in the page's blocks before is not
     * the run's doing and is skipped.
     */
    public static function savedHtmlValid(CheckContext $c): CheckResult
    {
        $problems = [];
        foreach ($c->after->pages() as $page) {
            $before = $c->before->pageByID((int) $page['id']);
            $old = [];
            foreach ($before['latestBlocks'] ?? [] as $block) {
                foreach ($block['data'] as $value) {
                    if (is_string($value)) {
                        $old[$value] = true;
                    }
                }
            }
            $oldText = implode("\n", array_keys($old));
            foreach ($page['latestBlocks'] ?? [] as $block) {
                foreach ($block['data'] as $field => $value) {
                    if (!is_string($value) || isset($old[$value])) {
                        continue;
                    }
                    $where = "{$page['path']} ({$block['area']}, {$block['type']}.{$field})";
                    $escapes = HtmlIntegrity::introducedEscapes($value, $oldText);
                    if ($escapes !== []) {
                        $problems[] = "{$where}: JSON escape sequences " . implode(' ', $escapes);
                    }
                    $malformed = HtmlIntegrity::hasTags($value) ? HtmlIntegrity::wellFormedProblem($value) : null;
                    if ($malformed !== null) {
                        $problems[] = "{$where}: HTML not well-formed ({$malformed})";
                    }
                }
            }
        }

        return self::result('saved_html_valid', $problems === [], 'saved HTML has escape sequences or is not well-formed: ' . implode('; ', $problems));
    }

    private static function result(string $name, bool $passed, string $detail = ''): CheckResult
    {
        return new CheckResult(CheckResult::STATE, $name, $passed, $passed ? '' : $detail);
    }

    private static function pageUnchanged(string $path, CheckContext $c): CheckResult
    {
        $name = "page_unchanged: {$path}";
        $before = $c->before->pageByPath($path);
        if ($before === null) {
            return self::result($name, false, 'no page at that path in the reference snapshot');
        }
        $after = $c->after->pageByID($before['id']);
        if ($after === null) {
            return self::result($name, false, 'the page no longer exists');
        }

        $changes = [];
        foreach (['parentID' => 'parent', 'isTrash' => 'trash status', 'versions' => 'number of versions'] as $key => $label) {
            if ($before[$key] !== $after[$key]) {
                $changes[] = $label . ' changed';
            }
        }
        if (($before['latestBlocks'] ?? null) !== ($after['latestBlocks'] ?? null) || ($before['attributes'] ?? null) !== ($after['attributes'] ?? null)) {
            $changes[] = 'latest content changed';
        }

        return self::result($name, $changes === [], implode(', ', $changes));
    }

    private static function pageType(mixed $spec, CheckContext $c): CheckResult
    {
        $path = (string) ($spec['page'] ?? '');
        $name = "page_type: {$path} is {$spec['handle']}";
        $page = $c->after->pageByPath($path);
        if ($page === null) {
            return self::result($name, false, 'no page at that path');
        }

        return self::result($name, $page['type'] === (string) $spec['handle'], "page type is \"{$page['type']}\"");
    }

    private static function attribute(mixed $spec, CheckContext $c): CheckResult
    {
        $path = (string) ($spec['page'] ?? '');
        $handle = (string) ($spec['handle'] ?? '');
        $name = "attribute: {$path} {$handle}";
        $page = $c->after->pageByPath($path);
        if ($page === null) {
            return self::result($name, false, 'no page at that path');
        }

        $actual = $page['attributes'][$handle] ?? null;

        return self::result($name, $actual === (string) $spec['equals'], $actual === null ? 'attribute is not set on the latest version' : 'latest version has ' . json_encode($actual));
    }

    private static function attributeOnChildren(mixed $spec, CheckContext $c): CheckResult
    {
        $path = (string) ($spec['page'] ?? '');
        $handle = (string) ($spec['handle'] ?? '');
        $name = "attribute_on_children_of: {$path} {$handle}";
        $ids = $c->start->childrenOf($path);
        if ($ids === null) {
            return self::result($name, false, 'page not found in the starting snapshot');
        }

        $wrong = [];
        foreach ($ids as $id) {
            $page = $c->after->pageByID($id);
            $actual = $page['attributes'][$handle] ?? null;
            if ($page === null || $actual !== (string) $spec['equals']) {
                $label = $c->start->pageByID($id)['path'] ?? (string) $id;
                $wrong[] = $label . ' (' . ($page === null ? 'page gone' : json_encode($actual)) . ')';
            }
        }

        return self::result($name, $wrong === [], 'not set on: ' . implode(', ', $wrong));
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<CheckResult>
     */
    private static function blocks(array $entry, CheckContext $c): array
    {
        $path = (string) $entry['page'];
        $area = isset($entry['area']) ? (string) $entry['area'] : null;
        $label = 'blocks: ' . $path . ($area !== null ? " [{$area}]" : '');

        $page = $c->after->pageByPath($path);
        if ($page === null || !isset($page['latestBlocks'])) {
            return [self::result($label, false, 'no fixture page at that path')];
        }
        $blocks = self::selectBlocks($page['latestBlocks'], $area);
        $beforePage = $c->before->pageByID($page['id']);
        $beforeBlocks = $beforePage === null ? [] : self::selectBlocks($beforePage['latestBlocks'] ?? [], $area);

        $results = [];
        foreach ($entry as $check => $spec) {
            switch ($check) {
                case 'page':
                case 'area':
                    break;
                case 'count':
                    $n = count(array_filter($blocks, static fn (array $b): bool => $b['type'] === (string) $spec['type']));
                    $results[] = self::result("{$label} count {$spec['type']} = {$spec['equals']}", $n === (int) $spec['equals'], "found {$n}");
                    break;
                case 'headings':
                    $found = self::headings($blocks);
                    foreach ($spec as $want) {
                        $ok = in_array(['text' => (string) $want['text'], 'level' => (int) $want['level']], $found, true);
                        $actual = array_map(static fn (array $h): string => 'h' . $h['level'] . ' "' . $h['text'] . '"', $found);
                        $results[] = self::result("{$label} heading h{$want['level']} \"{$want['text']}\"", $ok, 'headings found: ' . ($actual === [] ? '(none)' : implode(', ', $actual)));
                    }
                    break;
                case 'contains_html':
                    foreach ($spec as $needle) {
                        $results[] = self::result("{$label} contains " . json_encode($needle), str_contains(self::rawHtml($blocks), (string) $needle), 'not found in the blocks');
                    }
                    break;
                case 'lacks_html':
                    foreach ($spec as $needle) {
                        $results[] = self::result("{$label} lacks " . json_encode($needle), !str_contains(self::rawHtml($blocks), (string) $needle), 'still present in the blocks');
                    }
                    break;
                case 'unchanged':
                    if ($spec === true) {
                        $results[] = self::result("{$label} unchanged", self::comparable($blocks) === self::comparable($beforeBlocks), 'the blocks differ from the reference snapshot');
                    }
                    break;
                case 'has_block':
                    $results[] = self::result("{$label} has {$spec['type']} block", self::findBlock($blocks, $spec) !== null, 'no ' . $spec['type'] . ' block containing ' . json_encode($spec['containing'] ?? []));
                    break;
                case 'has_block_unchanged':
                    $found = self::findBlock($blocks, $spec);
                    $unchanged = $found !== null && in_array(self::comparableOne($found), self::comparable($beforeBlocks), true);
                    $results[] = self::result("{$label} has {$spec['type']} block, unchanged", $unchanged, $found === null ? 'no such block' : 'the block differs from the reference snapshot');
                    break;
                default:
                    $results[] = self::result("{$label} {$check}", false, 'unknown check');
            }
        }

        return $results;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private static function selectBlocks(array $blocks, ?string $area): array
    {
        if ($area === null) {
            return $blocks;
        }

        // A container's sub-area is named like "Main : 51 : Column 1".
        return array_values(array_filter($blocks, static fn (array $b): bool => $b['area'] === $area || str_ends_with($b['area'], ' : ' . $area)));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private static function rawHtml(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            foreach ($block['data'] as $value) {
                if (is_string($value)) {
                    $parts[] = $value;
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     *
     * @return list<array{text: string, level: int}>
     */
    private static function headings(array $blocks): array
    {
        $headings = [];
        foreach ($blocks as $block) {
            if (in_array($block['type'], ['content', 'html'], true)) {
                array_push($headings, ...Html::headings((string) ($block['data']['content'] ?? '')));
            }
        }

        return $headings;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $spec {type, containing: [...]}
     *
     * @return array<string, mixed>|null
     */
    private static function findBlock(array $blocks, array $spec): ?array
    {
        foreach ($blocks as $block) {
            if ($block['type'] !== (string) $spec['type']) {
                continue;
            }
            $raw = self::rawHtml([$block]);
            $text = Html::text($raw);
            $all = true;
            foreach ($spec['containing'] ?? [] as $needle) {
                if (!str_contains($raw, (string) $needle) && !str_contains($text, (string) $needle)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Blocks without their position, for comparing "the same blocks" in a reference and a later snapshot.
     *
     * @param list<array<string, mixed>> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private static function comparable(array $blocks): array
    {
        return array_map([self::class, 'comparableOne'], $blocks);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function comparableOne(array $block): array
    {
        return ['area' => $block['area'], 'type' => $block['type'], 'data' => $block['data']];
    }

    private static function pageText(array $blocks): string
    {
        $texts = [];
        foreach ($blocks as $block) {
            $texts[] = Html::text(self::rawHtml([$block]));
        }

        return Html::collapse(implode(' ', $texts));
    }

    private static function allTextUnchanged(string $path, CheckContext $c): CheckResult
    {
        $name = "all_text_unchanged: {$path}";
        $before = $c->before->pageByPath($path);
        $after = $before === null ? null : $c->after->pageByID($before['id']);
        if ($before === null || $after === null || !isset($before['latestBlocks'], $after['latestBlocks'])) {
            return self::result($name, false, 'page not found in both snapshots');
        }

        $a = self::pageText($before['latestBlocks']);
        $b = self::pageText($after['latestBlocks']);

        return self::result($name, $a === $b, 'text differs: before ' . mb_strlen($a) . ' characters, after ' . mb_strlen($b));
    }

    private static function liveVersionUnchanged(string $path, CheckContext $c): CheckResult
    {
        $name = "live_version_unchanged: {$path}";
        $before = $c->before->pageByPath($path);
        $after = $before === null ? null : $c->after->pageByID($before['id']);
        if ($before === null || $after === null) {
            return self::result($name, false, 'page not found in both snapshots');
        }

        $problems = [];
        if ($before['approvedVersion'] !== $after['approvedVersion']) {
            $problems[] = 'a different version is now approved (' . json_encode($before['approvedVersion']) . ' -> ' . json_encode($after['approvedVersion']) . ')';
        }
        if (($before['approvedBlocks'] ?? null) !== ($after['approvedBlocks'] ?? null)) {
            $problems[] = 'the approved version\'s blocks changed';
        }

        return self::result($name, $problems === [], implode('; ', $problems));
    }

    private static function fileExists(mixed $spec, CheckContext $c): CheckResult
    {
        $title = (string) ($spec['title'] ?? '');
        $name = "file_exists: {$title}";
        $files = $c->after->filesByTitle($title);
        if ($files === []) {
            return self::result($name, false, 'no file with that title');
        }

        $problems = [];
        if (isset($spec['in_folder']) && !in_array((string) $spec['in_folder'], array_column($files, 'folder'), true)) {
            $problems[] = 'not in folder "' . $spec['in_folder'] . '" (in ' . json_encode(array_column($files, 'folder')) . ')';
        }
        if (($spec['unchanged'] ?? false) === true && $files !== $c->before->filesByTitle($title)) {
            $problems[] = 'the file differs from the reference snapshot';
        }

        return self::result($name, $problems === [], implode('; ', $problems));
    }

    /**
     * @return list<CheckResult>
     */
    private static function people(mixed $spec, CheckContext $c): array
    {
        $people = $c->after->people();
        if ($people === null) {
            return [self::result('people', false, 'the People table does not exist')];
        }

        $results = [];
        foreach ($spec['exist'] ?? [] as $email) {
            $results[] = self::result("people exist: {$email}", isset($people[strtolower((string) $email)]), 'no such person');
        }
        foreach ($spec['absent'] ?? [] as $email) {
            $results[] = self::result("people absent: {$email}", !isset($people[strtolower((string) $email)]), 'the person exists');
        }
        if (isset($spec['total_test_people'])) {
            $n = 0;
            foreach ($people as $email => $row) {
                if (str_starts_with((string) ($row['name'] ?? ''), self::TEST_PERSON_PREFIX) && str_ends_with((string) $email, self::TEST_EMAIL_DOMAIN)) {
                    $n++;
                }
            }
            $results[] = self::result("people total_test_people = {$spec['total_test_people']}", $n === (int) $spec['total_test_people'], "found {$n}");
        }

        return $results;
    }

    private static function personFieldEmpty(mixed $spec, CheckContext $c): CheckResult
    {
        $email = strtolower((string) ($spec['email'] ?? ''));
        $field = (string) ($spec['field'] ?? '');
        $name = "person_field_empty: {$email} {$field}";
        $people = $c->after->people();
        if ($people === null || !isset($people[$email])) {
            return self::result($name, false, 'no such person');
        }
        if (!array_key_exists($field, $people[$email])) {
            return self::result($name, false, 'the People table has no column "' . $field . '"');
        }

        $value = $people[$email][$field];

        return self::result($name, $value === null || trim((string) $value) === '', 'the field holds ' . json_encode(mb_substr((string) $value, 0, 80)));
    }

    private static function changedPages(mixed $spec, CheckContext $c): CheckResult
    {
        $expected = [];
        $problems = [];
        foreach ($spec['exactly'] ?? [] as $entry) {
            if (is_array($entry) && isset($entry['children_of'])) {
                $ids = $c->start->childrenOf((string) $entry['children_of']);
                if ($ids === null) {
                    $problems[] = 'no page at ' . $entry['children_of'] . ' in the starting snapshot';
                    continue;
                }
                array_push($expected, ...$ids);
            } else {
                $page = $c->after->pageByPath((string) $entry);
                if ($page === null) {
                    $problems[] = 'no page at ' . $entry . ' to compare';
                    continue;
                }
                $expected[] = $page['id'];
            }
        }

        $expected = array_values(array_unique($expected));
        sort($expected);
        $actual = $c->after->changedPageIDs($c->before);

        $label = static function (array $ids) use ($c): string {
            return $ids === [] ? '(none)' : implode(', ', array_map(
                static fn (int $id): string => ($c->after->pageByID($id)['path'] ?? $c->before->pageByID($id)['path'] ?? (string) $id),
                $ids
            ));
        };

        $unexpected = array_values(array_diff($actual, $expected));
        $missing = array_values(array_diff($expected, $actual));
        if ($unexpected !== []) {
            $problems[] = 'changed but not expected: ' . $label($unexpected);
        }
        if ($missing !== []) {
            $problems[] = 'expected to change but did not: ' . $label($missing);
        }

        return self::result('changed_pages exactly: ' . $label($expected), $problems === [], implode('; ', $problems));
    }
}
