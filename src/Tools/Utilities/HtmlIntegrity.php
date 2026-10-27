<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

/**
 * HTML checks shared by the write tools' text check (TextIntegrity), the tool result encoding (ToolResultEncoding)
 * and the scenarios' saved_html_valid check (BF-7, Glyn's browser check 6, plans/BF-7.md §15).
 *
 * In check 6 a read result's HTML reached the model JSON-encoded ("<\/h2>\r\n\r\n<p>"), and the model wrote that text
 * back: the draft showed literal "<\/h2>" and "\r\n", and its headings were never closed.
 */
final class HtmlIntegrity
{
    /** JSON escape sequences as literal text: what a value copied from an encoded tool result looks like. */
    public const ESCAPE_SEQUENCES = ['<\/', '\r', '\n', '\t', '\"'];

    /** Elements with no closing tag. */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** A tag: closing slash, name, attributes, self-closing slash. Comments, doctypes and "<\/x>" don't match. */
    private const TAG = '/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)(?:\s[^<>]*?)?(\/?)>/';

    public static function hasTags(string $value): bool
    {
        return preg_match(self::TAG, $value) === 1 || str_contains($value, '<\/');
    }

    /**
     * The escape sequences in $value that $stored doesn't have (as many times): a sequence the stored text already holds
     * is the author's (a Windows path, a code sample).
     *
     * @return list<string>
     */
    public static function introducedEscapes(string $value, string $stored = ''): array
    {
        $found = [];
        foreach (self::ESCAPE_SEQUENCES as $sequence) {
            if (substr_count($value, $sequence) > substr_count($stored, $sequence)) {
                $found[] = $sequence;
            }
        }

        return $found;
    }

    /**
     * The introduced escape sequences that look copied from an encoded tool result (P3.3-2): "<\/" always; the others
     * only next to markup (after ">" or before "<", with spaces or other escapes between), inside a tag, or when "<\/"
     * is introduced too. A literal "\n" or "\"" in text can be the author's: a Windows path, a code example.
     * introducedEscapes() stays strict for the scenarios' saved_html_valid check.
     *
     * @return list<string>
     */
    public static function copiedEscapes(string $value, string $stored = ''): array
    {
        $introduced = self::introducedEscapes($value, $stored);
        if ($introduced === [] || in_array('<\/', $introduced, true)) {
            return $introduced;
        }

        $copied = [];
        foreach ($introduced as $sequence) {
            if (self::countNearMarkup($value, $sequence) > self::countNearMarkup($stored, $sequence)) {
                $copied[] = $sequence;
            }
        }

        return $copied;
    }

    private static function countNearMarkup(string $html, string $sequence): int
    {
        $count = 0;
        $offset = 0;
        $glue = '(?:[ \t]|\\\\[rnt])*';
        while (($position = strpos($html, $sequence, $offset)) !== false) {
            $offset = $position + strlen($sequence);
            $before = substr($html, 0, $position);
            $after = substr($html, $offset);
            $insideTag = strrpos($before, '<') !== false && strrpos($before, '<') > (int) strrpos($before, '>');
            if ($insideTag
                || preg_match('/>' . $glue . '$/', $before) === 1
                || preg_match('/^' . $glue . '</', $after) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Why the HTML is not well-formed (an element not closed, a closing tag with nothing open), or null. Void elements
     * and self-closing tags need no closing tag.
     */
    public static function wellFormedProblem(string $html): ?string
    {
        $open = [];
        preg_match_all(self::TAG, $html, $tags, PREG_SET_ORDER);
        foreach ($tags as [, $closing, $name, $selfClosing]) {
            $name = strtolower($name);
            if (in_array($name, self::VOID, true) || $selfClosing === '/') {
                continue;
            }
            if ($closing === '') {
                $open[] = $name;
                continue;
            }
            $top = array_pop($open);
            if ($top !== $name) {
                return $top === null
                    ? "a </{$name}> closes nothing"
                    : "<{$top}> is not closed before </{$name}>";
            }
        }

        return $open === [] ? null : '<' . end($open) . '> is never closed';
    }

    /**
     * How many elements of each name the HTML has (void elements included, closing tags not).
     *
     * @return array<string, int>
     */
    public static function elementCounts(string $html): array
    {
        $counts = [];
        preg_match_all(self::TAG, $html, $tags, PREG_SET_ORDER);
        foreach ($tags as [, $closing, $name]) {
            if ($closing === '') {
                $name = strtolower($name);
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * HTML as the model should see it in a tool result: no line breaks, so the encoded result holds no "\n" or "\r\n"
     * to copy back. Line breaks between elements go; inside text they become one space (which is how HTML shows them).
     * HTML with <pre> or <textarea>, where line breaks show, only gets its CR LF made LF.
     */
    public static function withoutLineBreaks(string $html): string
    {
        if (preg_match('/<(pre|textarea)\b/i', $html) === 1) {
            return str_replace(["\r\n", "\r"], "\n", $html);
        }

        $html = preg_replace('/>\s*\R\s*</', '><', $html) ?? $html;

        return trim(preg_replace('/\s*\R\s*/', ' ', $html) ?? $html);
    }
}
