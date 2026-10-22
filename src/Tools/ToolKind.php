<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use NeuronAI\Tools\ToolOutput;

/**
 * What a tool's name and result say about it. Shared by the scenario runner (tests/Eval/ToolCall) and by the
 * injection notice, which needs to know whether a write succeeded after flagged content was read.
 */
final class ToolKind
{
    /**
     * Tools whose name starts with one of these words only read. Any other tool counts as a write, so a new tool is
     * treated as a write until someone says otherwise (the safe way round).
     */
    public const READ_PREFIXES = ['get', 'list', 'search', 'find', 'count', 'describe', 'read', 'lookup', 'check', 'validate', 'preview', 'inspect', 'export', 'resolve'];

    public static function isWrite(string $toolName): bool
    {
        return preg_match('/^(' . implode('|', self::READ_PREFIXES) . ')_/', $toolName) !== 1;
    }

    /**
     * Did the tool report success? Tools return a JSON {ok: true|false, ...} envelope; the agent's tool error
     * handler turns an exception into "Error executing tool: ...". On Neuron 4 a missing or mistyped input is settled
     * as ToolOutput::error() without running the tool (P2.3).
     */
    public static function succeeded(string|ToolOutput $result): bool
    {
        if ($result instanceof ToolOutput) {
            if ($result->isError()) {
                return false;
            }
            $result = $result->getText();
        }

        $text = trim($result);
        if ($text === '' || preg_match('/^Error executing tool/i', $text) === 1) {
            return false;
        }

        $data = json_decode($text, true);
        if (is_array($data)) {
            // A ToolOutput::error() as Neuron 4 stores it: {"is_error": true, "blocks": [...]}.
            if (($data['is_error'] ?? false) === true) {
                return false;
            }
            if (array_key_exists('ok', $data)) {
                return (bool) $data['ok'];
            }
            if (array_key_exists('success', $data)) {
                return (bool) $data['success'];
            }
            if (!empty($data['error'])) {
                return false;
            }
        }

        return true;
    }
}
