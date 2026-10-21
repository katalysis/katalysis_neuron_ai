<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Security;

/**
 * The fixed notice shown under a reply when a tool result in that turn held text addressed to the AI assistant
 * (P1.11, B-INJ-3). The wording is here and nowhere else: the model never writes it, the panel only renders what the
 * server sends, and nothing reads the model's reply to decide whether to show it.
 *
 * The notice names where the text was read, taken from the NAME OF THE TOOL that returned it (sourceForTool()), never
 * from the content or the model: "this page", "a person's profile", "a block", "a file", or no name when the tool is
 * none of those or the turn read flagged text in more than one kind of place.
 *
 * The chat history stores only the KIND and the SOURCE on the turn's final assistant message (metadata keys META_KEY and
 * META_SOURCE_KEY), not the text, so a change of wording also applies to old chats. A message stored before the source
 * existed has no source and gets the plain wording.
 */
final class InjectionNotice
{
    /** Metadata key on the stored assistant message: the kind. */
    public const META_KEY = 'katalysis_notice';
    /** Metadata key on the stored assistant message: the source. */
    public const META_SOURCE_KEY = 'katalysis_notice_source';

    public const SUSPECTED = 'suspected_instructions';
    public const SUSPECTED_THEN_WRITES = 'suspected_instructions_then_writes';

    public const SOURCE_PAGE = 'page';
    public const SOURCE_PERSON = 'person';
    public const SOURCE_BLOCK = 'block';
    public const SOURCE_FILE = 'file';
    /** Any other tool, or more than one kind of source in one turn. */
    public const SOURCE_OTHER = 'other';

    private const SOURCE_LABELS = [
        self::SOURCE_PAGE => 'this page',
        self::SOURCE_PERSON => "a person's profile",
        self::SOURCE_BLOCK => 'a block',
        self::SOURCE_FILE => 'a file',
    ];

    /**
     * Which kind of place a tool reads from, from its name. The most specific word wins: list_page_blocks_by_type
     * reads blocks, not a page.
     *
     * @return string one of the SOURCE_ constants
     */
    public static function sourceForTool(string $toolName): string
    {
        $name = strtolower($toolName);

        return match (true) {
            str_contains($name, 'people'), str_contains($name, 'person') => self::SOURCE_PERSON,
            str_contains($name, 'file') => self::SOURCE_FILE,
            str_contains($name, 'block') => self::SOURCE_BLOCK,
            str_contains($name, 'page') => self::SOURCE_PAGE,
            default => self::SOURCE_OTHER,
        };
    }

    /**
     * @param string|null $kind one of SUSPECTED, SUSPECTED_THEN_WRITES
     * @param string|null $source one of the SOURCE_ constants; null or an unknown value gives the plain wording
     *
     * @return string|null the text for a kind, or null for no kind or an unknown one
     */
    public static function text(?string $kind, ?string $source = null): ?string
    {
        if ($kind !== self::SUSPECTED && $kind !== self::SUSPECTED_THEN_WRITES) {
            return null;
        }

        $label = self::SOURCE_LABELS[$source ?? ''] ?? null;
        $where = $label === null ? 'Some content the assistant read' : "Some content the assistant read ({$label})";

        return $kind === self::SUSPECTED
            ? "{$where} contains text addressed to the AI assistant. It was not acted on."
            // "Not acted on" can't be promised when a write succeeded after the flagged content was read.
            : "{$where} contained text addressed to the AI assistant, and changes were made afterwards in this reply. Please review them.";
    }

    /**
     * The stored chat history (JSON) with a "notice" text added to every assistant message that carries the
     * metadata key. Anything that isn't a JSON list of messages is returned as it was.
     */
    public static function annotateHistoryJson(string $json): string
    {
        $messages = json_decode($json, true);
        if (!is_array($messages) || $messages === []) {
            return $json;
        }

        $changed = false;
        foreach ($messages as $i => $message) {
            if (!is_array($message) || ($message['role'] ?? null) !== 'assistant') {
                continue;
            }
            // Neuron 3 stored metadata beside the message's own fields; Neuron 4 nests it under __meta (P2.1 spike).
            $meta = is_array($message['__meta'] ?? null) ? array_merge($message, $message['__meta']) : $message;
            if (!isset($meta[self::META_KEY])) {
                continue;
            }
            $source = isset($meta[self::META_SOURCE_KEY]) && is_string($meta[self::META_SOURCE_KEY]) ? $meta[self::META_SOURCE_KEY] : null;
            $text = is_string($meta[self::META_KEY]) ? self::text($meta[self::META_KEY], $source) : null;
            if ($text !== null) {
                $messages[$i]['notice'] = $text;
                $changed = true;
            }
        }
        if (!$changed) {
            return $json;
        }

        $encoded = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? $json : $encoded;
    }
}
