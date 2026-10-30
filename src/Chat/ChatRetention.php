<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

/**
 * Chat retention (P3.9-2, plans/P3.9-2.md, B-PII-5): how long chats are kept, and when the scheduled purge runs.
 *
 * - 90 days by default, `katalysis.ai.chat_retention_days`, 1 to 3650 on the settings page.
 * - A chat's age is its last activity (ChatPurge).
 * - The scheduled purge never takes a chat active in the last 10 minutes.
 * - The automated task (TASK_HANDLE) runs it on Concrete's scheduler; the chat endpoint runs it at most once a day as
 *   well (fallbackDue()), for sites without the scheduler's cron.
 */
final class ChatRetention
{
    public const CONFIG_KEY = 'katalysis.ai.chat_retention_days';
    public const LAST_RUN_KEY = 'katalysis.ai.chat_purge_last_run';
    public const DEFAULT_DAYS = 90;
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 3650;
    public const ACTIVE_MINUTES = 10;
    public const TASK_HANDLE = 'neuron_ai_purge_chats';
    public const FALLBACK_SECONDS = 86400;

    /** The retention in days: the setting, or the default when it is unset or not a whole number in range. */
    public static function days(mixed $setting): int
    {
        return self::isValid($setting) ? (int) $setting : self::DEFAULT_DAYS;
    }

    public static function isValid(mixed $value): bool
    {
        if (!is_scalar($value) || preg_match('/^\d+$/', trim((string) $value)) !== 1) {
            return false;
        }
        $days = (int) $value;

        return $days >= self::MIN_DAYS && $days <= self::MAX_DAYS;
    }

    /** Chats last active before this are purged: $days before $now, and never within the last ACTIVE_MINUTES. */
    public static function cutoff(int $days, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $byAge = $now->sub(new \DateInterval('P' . max(0, $days) . 'D'));
        $active = $now->sub(new \DateInterval('PT' . self::ACTIVE_MINUTES . 'M'));

        return min($byAge, $active);
    }

    /** Whether the chat endpoint's fallback should run: never run, or not for a day. */
    public static function fallbackDue(?int $lastRun, int $now): bool
    {
        return $lastRun === null || $now - $lastRun >= self::FALLBACK_SECONDS;
    }
}
