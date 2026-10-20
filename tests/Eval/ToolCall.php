<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Katalysis\NeuronAi\Tools\ToolKind;

/**
 * Helpers for a tool call recorded from the agent's chat history. The rules live in src/Tools/ToolKind, which the
 * injection notice shares, so the runner and the product can't disagree on what a write is.
 */
final class ToolCall
{
    public static function isWrite(string $toolName): bool
    {
        return ToolKind::isWrite($toolName);
    }

    public static function succeeded(string $result): bool
    {
        return ToolKind::succeeded($result);
    }
}
