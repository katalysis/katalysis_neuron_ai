<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Katalysis\NeuronAi\Approval\ApprovalService;
use Katalysis\NeuronAi\ConcreteCmsAgent;

/**
 * P3.6-2: since every write waits for approval, a test about something else answers every card with Approve, as the
 * scenario runner does (through ConcreteCmsAgent::decide()). The turn then goes on as it did before approval existed.
 */
final class ApproveAll
{
    /**
     * Sends the message, then approves every waiting call until the turn ends (at most 5 pauses), each decision on the
     * same agent. Returns the turn's final reply.
     */
    public static function turn(ConcreteCmsAgent $agent, string $message): string
    {
        return self::rest($agent, $agent->handleUserMessage($message));
    }

    /** Approves what the agent is waiting on after a reply, until the turn ends. */
    public static function rest(ConcreteCmsAgent $agent, string $reply): string
    {
        for ($round = 0; $agent->isAwaitingApproval() && $round < 5; $round++) {
            foreach ($agent->getApprovalCards() as $card) {
                if ($card['status'] !== ApprovalService::PENDING) {
                    continue;
                }
                $result = $agent->decide((string) $card['callId'], true);
                if ($result['reply'] !== null) {
                    $reply = $result['reply'];
                }
            }
        }

        return $reply;
    }
}
