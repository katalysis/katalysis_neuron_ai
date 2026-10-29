<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use Katalysis\NeuronAi\Tools\DisabledTools;
use Katalysis\NeuronAi\Tools\ToolGroups;

/**
 * Picks the tool groups for a request from the user's words (P3.2-2, plans/P3.2-2.md §2, option A): no model call,
 * the same answer every time.
 *
 * Each group has a word rule (RULES). A match in the user's message scores 2, in their message before it 1, so a
 * follow-up such as "and are there others?" keeps the last turn's groups. Quoted text is left out: it is a value
 * ('Headings Test Title'), not what the user asks for. The groups are taken best first (a tie in RULES' order, the
 * specific groups before content and pages), then content and pages (most requests are about the page the user is
 * on), each only while the request stays within ToolGroups::MAX_TOOLS. Groups the model asked for with
 * get_tool_group come before all of them.
 *
 * The groups picked are returned in ToolGroups::ORDER, not by score, so the same groups always make the same request.
 */
final class GroupRouter
{
    /** When nothing else decides: the page's content, then its pages. */
    public const DEFAULT = ['content', 'pages'];

    /** The word rules, in tie-break order. */
    public const RULES = [
        'attributes' => '/\b(attributes?|meta|seo|description|keywords|thumbnail|exclude from nav\w*|attribute sets?|propert(y|ies))\b/iu',
        'files' => '/\b(files?|pdfs?|folders?|uploads?|documents?|file manager|\w+\.(pdf|jpe?g|png|gif|svg|docx?|xlsx?))\b/iu',
        'users' => '/\b(users?|accounts?|members?|admins?|administrators?|logged in|who am i|email address(es)?)\b/iu',
        'topics' => '/\b(topics?|tags?|tagged|categor(y|ies)|taxonomy)\b/iu',
        'external' => '/\b(person|people|staff|team members?|places?|offices?|reviews?|specialisms?|biograph(y|ies)|bios?)\b/iu',
        'site_search' => '/\b(search|site-?wide|across the site|every page|all pages|how many|count|which pages|page types?)\b/iu',
        'pages' => '/\b(pages|sub-?pages?|child(ren)?|under|below|beneath|within|move|rename|publish(ed)?|drafts?|versions?|revert|restore|new page|create (a |the )?page|delete (this |the )?page|url|path|slug|sitemap)\b/iu',
        'content' => '/\b(h[1-6]|headings?|paragraphs?|text|wording|blocks?|images?|content|columns?|bold|links?|typos?|spelling|sections?|areas?|button|intro|copy|sentence)\b/iu',
    ];

    /** Quoted text: straight or curly quotes, not an apostrophe inside a word. */
    private const QUOTED = '/(?<![\p{L}\p{N}])([\'"‘“])[^\'"‘’“”\r\n]*[\'"’”](?![\p{L}\p{N}])/u';

    /**
     * The groups for a request, in ToolGroups::ORDER.
     *
     * @param list<string> $requested groups the model asked for in this turn (get_tool_group), latest last
     * @param array<string, int>|null $sizes tools in each group (ToolGroups::sizes()); null counts ToolGroups' lists
     *     without the disabled tools
     * @return list<string>
     */
    public static function route(string $message, ?string $previous, array $requested = [], ?array $sizes = null): array
    {
        $scores = [];
        $tieBreak = array_flip(array_keys(self::RULES));
        foreach (self::RULES as $group => $rule) {
            $score = 2 * self::matches($rule, $message) + ($previous === null ? 0 : self::matches($rule, $previous));
            if ($score > 0) {
                $scores[$group] = $score;
            }
        }
        // Best first; a tie in RULES' order.
        uksort($scores, static fn (string $a, string $b): int => [$scores[$b], $tieBreak[$a]] <=> [$scores[$a], $tieBreak[$b]]);

        $ranked = [...array_reverse($requested), ...array_keys($scores), ...self::DEFAULT];
        $picked = self::fit($ranked, $sizes ?? self::listedSizes());

        return array_values(array_intersect(ToolGroups::ORDER, $picked));
    }

    /**
     * The groups of $ranked, best first, each taken only while the "always" tools and the groups taken so far stay
     * within ToolGroups::MAX_TOOLS. Unknown groups and repeats are skipped.
     *
     * @param list<string> $ranked
     * @param array<string, int> $sizes
     * @return list<string> in $ranked's order
     */
    public static function fit(array $ranked, array $sizes): array
    {
        $total = $sizes['always'] ?? count(ToolGroups::ALWAYS);
        $picked = [];
        foreach ($ranked as $group) {
            if (!in_array($group, ToolGroups::ORDER, true) || in_array($group, $picked, true)) {
                continue;
            }
            $size = $sizes[$group] ?? 0;
            if ($total + $size <= ToolGroups::MAX_TOOLS) {
                $picked[] = $group;
                $total += $size;
            }
        }

        return $picked;
    }

    private static function matches(string $rule, string $text): int
    {
        return (int) preg_match_all($rule, preg_replace(self::QUOTED, ' ', $text) ?? $text);
    }

    /**
     * @return array<string, int>
     */
    private static function listedSizes(): array
    {
        $sizes = ['always' => count(ToolGroups::ALWAYS)];
        foreach (ToolGroups::GROUPS as $group => $tools) {
            $sizes[$group] = count(array_diff($tools, DisabledTools::names()));
        }

        return $sizes;
    }
}
