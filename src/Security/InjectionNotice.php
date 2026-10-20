<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Security;

/**
 * The fixed notice shown under a reply when a tool result in that turn held text addressed to the AI assistant
 * (P1.11, B-INJ-3). The wording is here and nowhere else: the model never writes it, the panel only renders what the
 * server sends, and nothing reads the model's reply to decide whether to show it.
 *
 * The chat history stores only the KIND on the turn's final assistant message (metadata key META_KEY), not the text,
 * so a change of wording also applies to old chats.
 */
final class InjectionNotice
{
    /** Metadata key on the stored assistant message. */
    public const META_KEY = 'katalysis_notice';

    public const SUSPECTED = 'suspected_instructions';
    public const SUSPECTED_THEN_WRITES = 'suspected_instructions_then_writes';

    public const TEXT = 'This page contains text addressed to the AI assistant. It was not acted on.';

    /** "Not acted on" can't be promised when a write succeeded after the flagged content was read. */
    public const TEXT_AFTER_WRITES = 'This content contained text addressed to the AI assistant, and changes were made afterwards in this reply. Please review them.';

    /**
     * @return string|null the text for a kind, or null for no kind or an unknown one
     */
    public static function text(?string $kind): ?string
    {
        return match ($kind) {
            self::SUSPECTED => self::TEXT,
            self::SUSPECTED_THEN_WRITES => self::TEXT_AFTER_WRITES,
            default => null,
        };
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
            if (!is_array($message) || ($message['role'] ?? null) !== 'assistant' || !isset($message[self::META_KEY])) {
                continue;
            }
            $text = is_string($message[self::META_KEY]) ? self::text($message[self::META_KEY]) : null;
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
