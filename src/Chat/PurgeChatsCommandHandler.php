<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Chat;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Support\Facade\Log;

/**
 * P3.9-2: the scheduled purge. Deletes the chats last active before the retention setting allows (ChatRetention),
 * at most ChatPurge::BATCH per run, and logs what went, in counts. The automated task and the chat endpoint's daily
 * fallback both run it.
 */
class PurgeChatsCommandHandler
{
    /**
     * @param ChatPurge|null $purge null: the real one
     * @param int|null $days null: the setting
     * @param \DateTimeImmutable|null $now null: now
     */
    public function __construct(private readonly ?ChatPurge $purge = null, private readonly ?int $days = null, private readonly ?\DateTimeImmutable $now = null)
    {
    }

    /**
     * @return array{chats: int, messages: int, approvals: int, runs: int, logs: int}
     */
    public function __invoke(PurgeChatsCommand $command): array
    {
        $purge = $this->purge ?? new ChatPurge(Application::getFacadeApplication()->make(Connection::class));
        $days = $this->days ?? ChatRetention::days(Config::get(ChatRetention::CONFIG_KEY));
        $cutoff = ChatRetention::cutoff($days, $this->now ?? new \DateTimeImmutable());

        $result = $purge->purge($cutoff);
        try {
            Log::info(sprintf(
                'Katalysis Neuron AI: chat retention (%d days): purged %d chats, %d messages, %d approvals, %d runs, %d log lines',
                $days,
                $result['chats'],
                $result['messages'],
                $result['approvals'],
                $result['runs'],
                $result['logs']
            ));
        } catch (\Throwable) {
            // A purge never fails over its own log line.
        }

        return $result;
    }
}
