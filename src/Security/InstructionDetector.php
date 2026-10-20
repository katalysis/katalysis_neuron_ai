<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Security;

/**
 * Looks for text addressed to the AI assistant in the content a tool returns (P1.11, B-INJ-1 to B-INJ-3).
 *
 * It is an alarm, not a guard: it never blocks a read and never changes content, and it is not an LLM check. A hit
 * makes the server show the person a fixed notice (InjectionNotice) and tells the model which field to distrust.
 * It misses paraphrases, other languages, instructions split across fields, encoded text and text inside images.
 * The real protection against a missed injection is approval of writes (P3.6).
 *
 * A field is flagged when one STRONG pattern matches, or when two different WEAK kinds match in the same field. One
 * weak pattern alone never flags, so ordinary help text ("Delete the page you no longer need") passes.
 *
 * No Concrete, no database: unit-testable on its own.
 */
final class InstructionDetector
{
    public const IGNORE_PREVIOUS = 'ignore_previous';
    public const ADDRESSED_TO_AI = 'addressed_to_ai';
    public const CHAT_MARKERS = 'chat_markers';
    public const EXFIL_LINK = 'exfil_link';
    public const CONCEAL_FROM_USER = 'conceal_from_user';
    public const TOOL_COMMAND = 'tool_command';
    public const ROLE_CHANGE = 'role_change';

    /** Longest part of one field that is scanned, in bytes. */
    public const MAX_FIELD_BYTES = 200000;
    /** Longest excerpt returned with a hit, in characters. */
    public const EXCERPT_LENGTH = 120;
    /** Array depth that is followed. */
    private const MAX_DEPTH = 12;

    /** Keys that name the thing a field belongs to; the nearest one is reported as the hit's source. */
    private const SOURCE_KEYS = ['path', 'pagePath', 'collectionPath', 'blockID', 'bID', 'pageID', 'cID', 'sID', 'id', 'ID'];

    private const WORDS_BEFORE = '(?:\W+\w+){0,3}?';

    /**
     * Patterns by kind, with what each runs on:
     * - 'text': the text with tags removed and whitespace collapsed (so markup can't split a phrase);
     * - 'raw': the decoded text with its tags kept (chat markers and links are made of markup).
     *
     * @var array<string, array{strong: bool, on: string, regex: string}>
     */
    private const PATTERNS = [
        self::IGNORE_PREVIOUS => [
            'strong' => true,
            'on' => 'text',
            'regex' => '/\b(?:ignore|disregard|forget|override)\b(?:\W+\w+){0,3}?\W+(?:previous|prior|earlier|above|all|your)\b'
                . '(?:\W+\w+){0,3}?\W+(?:instructions?|rules|prompts?|guidelines)\b/iu',
        ],
        self::ADDRESSED_TO_AI => [
            'strong' => true,
            'on' => 'text',
            'regex' => '/\b(?:note|message|instructions?|attention|important|notice)\s+(?:to|for)\s+(?:the\s+)?'
                . '(?:(?:ai|llm|chatbot)\b|language\s+model\b|ai\s+(?:assistant|agent|model|system)\b|assistant\s*(?=[:,]))/iu',
        ],
        self::CHAT_MARKERS => [
            'strong' => true,
            'on' => 'raw',
            'regex' => '/<\|im_(?:start|end)\|>|\[\/?INST\]|<<\/?SYS>>|^[ \t]*#{2,4}[ \t]*system\b/imu',
        ],
        self::CONCEAL_FROM_USER => [
            'strong' => false,
            'on' => 'text',
            'regex' => '/\b(?:do\s+not|don\'?t|never|without)\b(?:\W+\w+){0,2}?\W+(?:tell|telling|inform|informing|mention|mentioning|'
                . 'reveal|revealing|alert|alerting|notify|notifying|disclose|disclosing)\b(?:\W+\w+){0,3}?\W+(?:the\s+)?'
                . '(?:user|human|reader|person)s?\b|\bkeep\s+this\s+(?:a\s+)?secret\b/iu',
        ],
        self::TOOL_COMMAND => [
            'strong' => false,
            'on' => 'text',
            'regex' => '/\b(?:delete|remove|publish|approve|move|rename|change|update|send|email|run|erase)\b' . self::WORDS_BEFORE
                . '\W+(?:pages?|files?|users?|persons?|people|blocks?|versions?|accounts?|passwords?)\b/iu',
        ],
        self::ROLE_CHANGE => [
            'strong' => false,
            'on' => 'text',
            'regex' => '/\byou\s+are\s+now\b|\bfrom\s+now\s+on\s+you\b|\bnew\s+instructions\b|\byour\s+new\s+task\b|\bact\s+as\b|'
                . '\bpretend\s+to\s+be\b|^[ \t]*(?:system|assistant)[ \t]*:/imu',
        ],
    ];

    /** Tags that end a line; the rest are removed without a break. */
    private const BLOCK_TAGS = 'p|div|br|li|ul|ol|h[1-6]|tr|td|th|table|section|article|header|footer|blockquote|pre|hr|dd|dt|dl';

    public function __construct(private readonly ?string $ownHost = null)
    {
    }

    /**
     * Scans every string in a tool result (the keys are not scanned).
     *
     * @return list<array{kind: string, field: string, excerpt: string, source?: string}> empty when nothing is flagged
     */
    public function scan(mixed $result): array
    {
        $hits = [];
        $this->walk($result, '', null, 0, $hits);

        return $hits;
    }

    /**
     * Scans one string. The field name is only copied into the hits.
     *
     * @return list<array{kind: string, field: string, excerpt: string}>
     */
    public function scanText(string $text, string $field = ''): array
    {
        $normalised = $this->normalise($text);
        $raw = $normalised['raw'];
        $plain = $normalised['text'];
        if ($raw === '') {
            return [];
        }

        $matches = [];
        foreach (self::PATTERNS as $kind => $pattern) {
            $subject = $pattern['on'] === 'raw' ? $raw : $plain;
            if (@preg_match($pattern['regex'], $subject, $m, PREG_OFFSET_CAPTURE) === 1) {
                $matches[$kind] = $this->excerpt($subject, (int) $m[0][1], strlen($m[0][0]));
            }
        }
        $link = $this->findExfilLink($raw);
        if ($link !== null) {
            $matches[self::EXFIL_LINK] = $link;
        }

        $strong = array_filter(array_keys($matches), static fn (string $k): bool => $k === self::EXFIL_LINK || self::PATTERNS[$k]['strong']);
        $weak = array_diff(array_keys($matches), $strong);
        if ($strong === [] && count($weak) < 2) {
            return [];
        }

        $hits = [];
        foreach ($matches as $kind => $excerpt) {
            $hits[] = ['kind' => $kind, 'field' => $field, 'excerpt' => $excerpt];
        }

        return $hits;
    }

    /**
     * @param list<array<string, mixed>> $hits
     */
    private function walk(mixed $value, string $field, ?string $source, int $depth, array &$hits): void
    {
        if (is_string($value)) {
            foreach ($this->scanText($value, $field) as $hit) {
                if ($source !== null) {
                    $hit['source'] = $source;
                }
                $hits[] = $hit;
            }

            return;
        }

        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        if (is_object($value)) {
            $value = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
        }
        if (!is_array($value)) {
            return;
        }

        $here = $source;
        foreach (self::SOURCE_KEYS as $key) {
            if (isset($value[$key]) && is_scalar($value[$key]) && (string) $value[$key] !== '') {
                $here = $key . ' ' . mb_substr((string) $value[$key], 0, 80);
                break;
            }
        }

        foreach ($value as $key => $item) {
            $this->walk($item, $field === '' ? (string) $key : $field . '.' . $key, $here, $depth + 1, $hits);
        }
    }

    /**
     * @return array{raw: string, text: string}
     *   raw: decoded, hidden characters removed, tags kept. text: tags removed, one space between words.
     */
    private function normalise(string $text): array
    {
        if (strlen($text) > self::MAX_FIELD_BYTES) {
            $text = substr($text, 0, self::MAX_FIELD_BYTES);
        }
        if ($text === '') {
            return ['raw' => '', 'text' => ''];
        }
        // Invalid UTF-8 (a cut multi-byte character, a bad import) becomes "?" instead of breaking the patterns.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $raw = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Zero-width and format characters, soft hyphens, and control characters other than tab and line breaks.
        $raw = preg_replace('/[\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}\x{00AD}\x{202A}-\x{202E}]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? $raw;
        $raw = str_replace("\r", "\n", $raw);

        $plain = preg_replace('~</?(?:' . self::BLOCK_TAGS . ')\b[^>]*>~i', "\n", $raw) ?? $raw;
        $plain = preg_replace('~<[^>]*>~', '', $plain) ?? $plain;
        $plain = preg_replace('/[^\S\n]+/u', ' ', $plain) ?? $plain;
        $plain = preg_replace('/ ?\n ?/', "\n", $plain) ?? $plain;

        return ['raw' => $raw, 'text' => trim($plain)];
    }

    /**
     * A Markdown image or link, or an <img> or <a>, to an absolute URL on another host whose query has a
     * placeholder value (?d=EMAIL, {user_email}, [email]).
     */
    private function findExfilLink(string $raw): ?string
    {
        if (preg_match_all('~(?:\]\(|\b(?:src|href)\s*=\s*["\']?)\s*(https?://[^\s"\'<>)]+)~i', $raw, $m, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        foreach ($m[1] as [$url, $offset]) {
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['host']) || !isset($parts['query'])) {
                continue;
            }
            if ($this->ownHost !== null && strcasecmp($parts['host'], $this->ownHost) === 0) {
                continue;
            }
            foreach (explode('&', $parts['query']) as $pair) {
                $value = urldecode(substr((string) strstr($pair, '='), 1));
                if (preg_match('/^(?:[A-Z][A-Z0-9_]{2,}|\{\{?[^{}]*\}\}?|\[[^\[\]]*\]|<[^<>]*>|\$\{[^{}]*\})$/', $value) === 1) {
                    return $this->excerpt($raw, (int) $offset, strlen($url));
                }
            }
        }

        return null;
    }

    /**
     * Up to EXCERPT_LENGTH characters around a match, on one line. $byteOffset and $byteLength are in bytes, as
     * preg_match reports them.
     */
    private function excerpt(string $subject, int $byteOffset, int $byteLength): string
    {
        $start = mb_strlen(substr($subject, 0, $byteOffset));
        $length = max(1, mb_strlen(substr($subject, $byteOffset, $byteLength)));
        $from = max(0, $start - (int) floor(max(0, self::EXCERPT_LENGTH - $length) / 2));
        $slice = mb_substr($subject, $from, self::EXCERPT_LENGTH);
        $slice = trim(preg_replace('/\s+/u', ' ', $slice) ?? $slice);

        return mb_substr($slice, 0, self::EXCERPT_LENGTH);
    }
}
