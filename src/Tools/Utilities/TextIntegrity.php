<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

/**
 * The text check for tools that replace block text (CLAUDE.md rule 7, B-T1-2, B-TRU-7; plans/BF-5.md §5).
 *
 * Moved out of PagesToolkit in BF-5 so update_block_in_page_area, batch_update_page_content and
 * batch_update_content_headings share it. Modes:
 * - keep_text (default): every piece of existing text must still be there; text may be added and moved.
 * - formatting_only: the text must stay identical; only markup changes.
 * - scoped_text: the fields in textFields may change, but existing text may only go if it is inside one of the
 *   replacedText strings, which must be in the stored text. Other fields stay identical.
 *
 * In every mode, a value that looks built from a shortened read (stored text cut off and followed by "..."), is refused.
 * In every mode too (BF-7, browser check 6), a value with JSON escape sequences the stored text doesn't have ("<\/",
 * a literal "\r\n"), or whose HTML is no longer well-formed, is refused; under keep_text no element may be removed.
 */
final class TextIntegrity
{
    public const MODES = ['keep_text', 'formatting_only', 'scoped_text'];

    public const DEFAULT_MODE = 'keep_text';

    /** Text before an ellipsis is compared with the stored text only when it is at least this long. */
    private const SHORTENED_MIN = 12;

    /** How much of the text before an ellipsis is compared. */
    private const SHORTENED_WINDOW = 30;

    /**
     * @throws \RuntimeException for an unknown mode (freeform was removed in BF-5)
     */
    public static function mode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        if ($mode === '') {
            return self::DEFAULT_MODE;
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new \RuntimeException('changeMode must be one of: ' . implode(', ', self::MODES) . '. Nothing was changed.');
        }

        return $mode;
    }

    /**
     * The text of a value: each tag counts as a space (as the scenarios' all_text_unchanged check reads it), entities
     * are decoded and whitespace collapsed. Non-strings give ''.
     */
    public static function normalize($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $text = preg_replace('/<[^>]*>/', ' ', $value) ?? $value;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * The pieces of text between tags, normalised, in order.
     *
     * @return list<string>
     */
    public static function segments($value): array
    {
        if (!is_string($value)) {
            return [];
        }

        $segments = [];
        foreach (preg_split('/<[^>]*>/', $value) ?: [] as $piece) {
            $piece = self::normalize($piece);
            if ($piece !== '') {
                $segments[] = $piece;
            }
        }

        return $segments;
    }

    /**
     * Checks a proposed block value against the stored record.
     *
     * @param array<string, mixed> $before the stored record
     * @param array<string, mixed> $proposed the value to save
     * @param list<string>|null $textFields fields allowed to change (scoped_text)
     * @param list<string>|null $replacedText stored text the change replaces (scoped_text)
     *
     * @return array{ok: bool, message: string, watchedFields: list<string>, fields: array<string, array{unchanged: bool, orderChanged: bool, removedText: list<string>}>}
     */
    public static function evaluate(array $before, array $proposed, string $mode, ?array $textFields = null, ?array $replacedText = null): array
    {
        $listed = self::cleanList($textFields);
        $replaced = array_values(array_filter(array_map([self::class, 'normalize'], self::cleanList($replacedText)), static fn (string $t): bool => $t !== ''));
        $watched = self::watchedFields($before, $proposed, $listed);
        $fail = static fn (string $message): array => ['ok' => false, 'message' => $message, 'watchedFields' => $watched, 'fields' => []];

        if ($mode === 'scoped_text' && $listed === []) {
            return $fail('scoped_text mode requires textFields: the fields whose text may change. Nothing was changed.');
        }
        if ($mode !== 'scoped_text' && $replaced !== []) {
            return $fail('replacedText is used only with changeMode "scoped_text". Nothing was changed.');
        }
        foreach ($replaced as $text) {
            $found = false;
            foreach ($listed as $field) {
                if (str_contains(self::normalize($before[$field] ?? null), $text)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return $fail(sprintf(
                    'replacedText "%s" was not found in the stored text of %s. Name text exactly as it is stored (get_block_data returns it). Nothing was changed.',
                    self::clip($text),
                    implode(', ', array_map(static fn (string $f): string => "'{$f}'", $listed))
                ));
            }
        }

        $fields = [];
        foreach ($watched as $field) {
            if (!array_key_exists($field, $proposed)) {
                continue;
            }

            $escapes = self::escapeProblem($field, $before[$field] ?? null, $proposed[$field]);
            if ($escapes !== null) {
                return $fail($escapes);
            }

            $beforeText = self::normalize($before[$field] ?? null);
            $afterText = self::normalize($proposed[$field]);

            $cut = self::shortenedSource($beforeText, $afterText);
            if ($cut !== null) {
                return $fail(sprintf(
                    "Field '%s' looks like it was built from a shortened read: it has \"%s...\" where the stored text goes on. "
                    . 'Read the block with get_block_data, which returns the full text, and try again. Nothing was changed.',
                    $field,
                    $cut
                ));
            }

            $missing = [];
            $positions = [];
            foreach (self::segments($before[$field] ?? null) as $segment) {
                $position = mb_strpos($afterText, $segment);
                if ($position === false) {
                    $missing[] = $segment;
                } else {
                    $positions[] = $position;
                }
            }
            $sorted = $positions;
            sort($sorted);
            $orderChanged = $positions !== $sorted;

            $isListed = in_array($field, $listed, true);
            if ($mode === 'formatting_only' && $beforeText !== $afterText) {
                return $fail("formatting_only violation in field '{$field}': textual content changed. Nothing was changed.");
            }
            if ($mode === 'keep_text' && $missing !== []) {
                return $fail(sprintf(
                    "Field '%s': the new value leaves out existing text: \"%s\". Existing text is kept unless changeMode says otherwise "
                    . '(keep_text, the default). Nothing was changed. If the user asked to change or remove that text, send changeMode '
                    . '"scoped_text" with textFields ["%s"] and replacedText listing the stored text being replaced. Otherwise read the '
                    . 'full block with get_block_data and keep its text.',
                    $field,
                    self::clip($missing[0]),
                    $field
                ));
            }
            if ($mode === 'scoped_text' && !$isListed && $beforeText !== $afterText) {
                return $fail("scoped_text violation in field '{$field}': its text changed but it is not in textFields. Nothing was changed.");
            }
            if ($mode === 'scoped_text' && $isListed) {
                foreach ($missing as $segment) {
                    if (!self::coveredBy($segment, $replaced)) {
                        return $fail(sprintf(
                            "Field '%s': the new value leaves out \"%s\", which is not in replacedText. List every piece of stored text "
                            . 'the change replaces or removes in replacedText. Nothing was changed.',
                            $field,
                            self::clip($segment)
                        ));
                    }
                }
            }

            // Last, so a lost piece of text is reported as such first.
            $structure = self::structureProblem($field, $before[$field] ?? null, $proposed[$field], $mode);
            if ($structure !== null) {
                return $fail($structure);
            }

            $fields[$field] = [
                'unchanged' => $beforeText === $afterText,
                'orderChanged' => $orderChanged,
                'removedText' => $missing,
            ];
        }

        return ['ok' => true, 'message' => 'text check passed', 'watchedFields' => $watched, 'fields' => $fields];
    }

    /**
     * The check for a value with nothing stored before it (add_block_to_page_area): no JSON escape sequences, and
     * well-formed HTML. The reason, or null.
     *
     * @param array<string, mixed> $proposed
     */
    public static function checkNewValue(array $proposed): ?string
    {
        foreach ($proposed as $field => $value) {
            $problem = self::escapeProblem((string) $field, null, $value) ?? self::structureProblem((string) $field, null, $value, 'new');
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * JSON escape sequences the stored value doesn't have, for one field. The reason, or null.
     */
    private static function escapeProblem(string $field, $stored, $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $stored = is_string($stored) ? $stored : '';

        $escapes = HtmlIntegrity::introducedEscapes($value, $stored);
        if ($escapes !== []) {
            if (in_array('\\r', $escapes, true) && in_array('\\n', $escapes, true)) {
                $escapes = array_values(array_diff($escapes, ['\\r', '\\n']));
                $escapes[] = '\\r\\n';
            }

            return sprintf(
                "Field '%s' contains JSON escape sequences as literal text (%s): it looks copied from an encoded tool result, "
                . 'and they would show on the page. Send the HTML itself: "</p>", not "<\\/p>", and no "\\r\\n" or "\\n" between '
                . 'tags. Nothing was changed.',
                $field,
                implode(', ', $escapes)
            );
        }

        return null;
    }

    /**
     * Well-formedness and, under keep_text, removed elements, for one field. The reason, or null.
     */
    private static function structureProblem(string $field, $stored, $value, string $mode): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $stored = is_string($stored) ? $stored : '';

        if (!HtmlIntegrity::hasTags($value)) {
            return null;
        }
        $problem = HtmlIntegrity::wellFormedProblem($value);
        if ($problem !== null && ($stored === '' || HtmlIntegrity::wellFormedProblem($stored) === null)) {
            return "Field '{$field}': the new HTML is not well-formed ({$problem}). Nothing was changed. Send complete HTML, "
                . 'with every element closed.';
        }

        if ($mode === 'keep_text' && $stored !== '') {
            // Elements may be renamed (a heading level, a paragraph made a heading) or added, but not removed: a lost
            // link, list item or paragraph break is lost content even when every word is kept.
            $before = HtmlIntegrity::elementCounts($stored);
            $after = HtmlIntegrity::elementCounts($value);
            if (array_sum($after) < array_sum($before)) {
                $lost = [];
                foreach ($before as $name => $count) {
                    if (($after[$name] ?? 0) < $count) {
                        $lost[] = "<{$name}>";
                    }
                }

                return sprintf(
                    "Field '%s': the tag structure changed: the new HTML has %d elements where the stored HTML has %d (fewer %s). "
                    . 'keep_text (the default) keeps every existing element, so links, paragraphs and list items are not lost. '
                    . 'Nothing was changed. If the user asked to change the markup, send changeMode "formatting_only" (text '
                    . 'unchanged) or "scoped_text".',
                    $field,
                    array_sum($after),
                    array_sum($before),
                    implode(', ', $lost)
                );
            }
        }

        return null;
    }

    /**
     * Fills the fields a value leaves out from the stored record, so an update changes only what it names. Empty and
     * zero stored values are not copied: a block's save() treats a missing field as empty or off, and some read a
     * present "0" as on. An explicit value, including '', is kept as given. Fields that a block's save() rebuilds from
     * form inputs (a link picker, for example) are not covered.
     *
     * @param array<string, mixed> $before the stored record
     * @param array<string, mixed> $proposed
     *
     * @return array{value: array<string, mixed>, keptFields: list<string>}
     */
    public static function keepOmittedFields(array $before, array $proposed): array
    {
        $kept = [];
        foreach ($before as $field => $value) {
            if ($field === 'bID' || array_key_exists($field, $proposed)) {
                continue;
            }
            if ($value === null || $value === '' || (is_numeric($value) && (float) $value === 0.0)) {
                continue;
            }
            $proposed[$field] = $value;
            $kept[] = (string) $field;
        }

        return ['value' => $proposed, 'keptFields' => $kept];
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    public static function checksums(array $record, array $fields): array
    {
        $checksums = [];
        foreach ($fields as $field) {
            $checksums[$field] = sha1(self::normalize($record[$field] ?? null));
        }

        return $checksums;
    }

    /**
     * String fields of the proposed value that are strings in the stored record, plus the textFields named.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $proposed
     * @param list<string> $listed
     *
     * @return list<string>
     */
    private static function watchedFields(array $before, array $proposed, array $listed): array
    {
        $fields = [];
        foreach ($proposed as $field => $value) {
            if (is_string($field) && is_string($value) && is_string($before[$field] ?? null)) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique(array_merge($fields, $listed)));
    }

    /**
     * The text before an ellipsis in the new value, when the stored text has that same text but goes on without one.
     */
    private static function shortenedSource(string $beforeText, string $afterText): ?string
    {
        if (!preg_match_all('/\.\.\.|…/u', $afterText, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches[0] as [, $offset]) {
            $lead = rtrim(substr($afterText, 0, $offset));
            if (mb_strlen($lead) < self::SHORTENED_MIN) {
                continue;
            }
            $tail = mb_substr($lead, -self::SHORTENED_WINDOW);
            if (!str_contains($beforeText, $tail)) {
                continue;
            }
            if (preg_match('/' . preg_quote($tail, '/') . '\s*(\.\.\.|…)/u', $beforeText)) {
                continue;
            }

            return $tail;
        }

        return null;
    }

    /**
     * @param list<string> $replaced
     */
    private static function coveredBy(string $segment, array $replaced): bool
    {
        foreach ($replaced as $text) {
            if (str_contains($text, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function cleanList(?array $values): array
    {
        $clean = [];
        foreach ($values ?? [] as $value) {
            if (is_string($value) && trim($value) !== '') {
                $clean[] = trim($value);
            }
        }

        return array_values(array_unique($clean));
    }

    private static function clip(string $text): string
    {
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . ' [...]' : $text;
    }
}
