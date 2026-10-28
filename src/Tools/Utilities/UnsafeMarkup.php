<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

/**
 * Code in block text (P3.3-2, B-T1-10: "HTML in content is content"). The block write tools refuse a value that adds a
 * <script> element, an event-handler attribute (onclick=, onerror=, ...) or a javascript: URL. An injected instruction
 * could ask for one, and the spec found no sanitising.
 *
 * Only what the value adds is refused: an HTML block that already holds an embed script keeps it. Text about these
 * things ("never paste javascript: links") is not markup and is allowed.
 *
 * P3.3-7: a whole value that is a javascript:, data: or vbscript: URL is refused too. Link columns (a Feature's
 * externalLink, a slide's linkURL) are saved as given in import mode, without the block form's URL sanitising.
 */
final class UnsafeMarkup
{
    /** Schemes refused as a whole value. */
    private const BARE_SCHEMES = ['javascript', 'data', 'vbscript'];

    /** An opening tag with its attributes. */
    private const TAG = '/<[a-zA-Z][a-zA-Z0-9-]*(\s[^<>]*)?\/?>/';

    /** An attribute and its value, quoted or not. */
    private const ATTRIBUTE = '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+)/';

    /**
     * What the value adds that the stored value doesn't have (as many times): "<script>", "event-handler attribute
     * (onerror)", "javascript: URL".
     *
     * @return list<string>
     */
    public static function introduced(string $value, string $stored = ''): array
    {
        $after = self::find($value);
        $before = self::find($stored);

        $added = [];
        foreach ($after as $what => $count) {
            if ($count > ($before[$what] ?? 0)) {
                $added[] = $what;
            }
        }

        return $added;
    }

    /**
     * The refusal for one field, or null.
     */
    public static function problem(string $field, $value, $stored = null): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $added = self::introduced($value, is_string($stored) ? $stored : '');
        if ($added === []) {
            return null;
        }

        return sprintf(
            "Field '%s' adds code to the page (%s). The assistant doesn't put code into page content (B-T1-10): if the user "
            . 'needs a script or an interactive element, a developer or a site administrator adds it. Send the content '
            . 'without it. Nothing was changed.',
            $field,
            implode(', ', $added)
        );
    }

    /**
     * @return array<string, int>
     */
    private static function find(string $html): array
    {
        $found = [];
        $add = static function (string $what) use (&$found): void {
            $found[$what] = ($found[$what] ?? 0) + 1;
        };

        // Browsers ignore whitespace and control characters inside the scheme ("java\tscript:").
        $bare = strtolower((string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        foreach (self::BARE_SCHEMES as $scheme) {
            if (str_starts_with($bare, $scheme . ':')) {
                $add("{$scheme}: URL");
            }
        }

        $scripts = preg_match_all('/<script\b/i', $html);
        for ($i = 0; $i < (int) $scripts; $i++) {
            $add('<script>');
        }

        preg_match_all(self::TAG, $html, $tags);
        foreach ($tags[1] as $attributes) {
            preg_match_all(self::ATTRIBUTE, (string) $attributes, $pairs, PREG_SET_ORDER);
            foreach ($pairs as [, $name, $raw]) {
                $name = strtolower($name);
                if (str_starts_with($name, 'on')) {
                    $add("event-handler attribute ({$name})");
                    continue;
                }
                $url = html_entity_decode(trim($raw, '"\''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Browsers ignore whitespace and control characters inside the scheme ("java\tscript:").
                $url = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $url));
                if (str_starts_with($url, 'javascript:')) {
                    $add('javascript: URL');
                }
            }
        }

        return $found;
    }
}
