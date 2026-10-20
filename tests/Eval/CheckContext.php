<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * What the checks look at.
 *
 * - $before: the reference state. For a turn's own `expect` it is the snapshot taken before that turn.
 *   For the scenario-level `expect` it is the snapshot taken before the first turn.
 * - $start: always the snapshot taken before the first turn. Sets of pages that a scenario names as
 *   `children_of` are the ones recorded here.
 * - $after: the snapshot taken after the turn (or the last turn).
 * - $toolCalls: the tool calls of that turn (or of every turn, for the scenario-level `expect`).
 * - $reply and $prompt: the assistant's reply, and the user's message it answered (the last ones, for the scenario level).
 * - $notice: the injection notice text the agent reported for that turn (ConcreteCmsAgent::getTurnNotice(), the call
 *   controllers/chat.php makes), or null. For the scenario-level `expect` it is the first notice of any turn.
 */
final class CheckContext
{
    /**
     * @param list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}> $toolCalls
     */
    public function __construct(
        public readonly Snapshot $before,
        public readonly Snapshot $start,
        public readonly Snapshot $after,
        public readonly array $toolCalls,
        public readonly string $reply,
        public readonly string $prompt,
        public readonly ?string $notice = null,
    ) {
    }
}
