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
 * - $runToolCalls and $runPrompts (BF-12): every tool call and every user message of the run so far, this turn
 *   included. Null means the same as $toolCalls and [$prompt]. no_invented_hosts takes its allowed hosts from them.
 */
final class CheckContext
{
    /**
     * @param list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}> $toolCalls
     * @param list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}>|null $runToolCalls
     * @param list<string>|null $runPrompts
     * @param list<array{tool: string, callId: string, decision: string, status: string}> $approvals P3.6-1: the calls the
     *     turn (or, at scenario level, the run) waited on for approval, with the runner's answer and the outcome
     */
    public function __construct(
        public readonly Snapshot $before,
        public readonly Snapshot $start,
        public readonly Snapshot $after,
        public readonly array $toolCalls,
        public readonly string $reply,
        public readonly string $prompt,
        public readonly ?string $notice = null,
        public readonly ?array $runToolCalls = null,
        public readonly ?array $runPrompts = null,
        public readonly array $approvals = [],
    ) {
    }

    /**
     * @return list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}>
     */
    public function runCalls(): array
    {
        return $this->runToolCalls ?? $this->toolCalls;
    }

    /**
     * @return list<string>
     */
    public function prompts(): array
    {
        return $this->runPrompts ?? [$this->prompt];
    }
}
