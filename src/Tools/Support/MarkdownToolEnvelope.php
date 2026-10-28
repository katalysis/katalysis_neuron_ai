<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Support;

use Concrete\Core\User\User;

/**
 * The guard for the Blocks and Versions tools, which answer in markdown and never went through guarded() (P3.5-1).
 *
 * - A guest is refused, as every other toolkit's guarded() refuses one (thrown, so the agent words it as a failure).
 * - A refusal or error becomes {ok: false, error}: their "❌ Error ..." strings, and anything thrown. Before, those
 *   strings read as success to ToolKind, so a refused write looked like one that worked.
 * - A successful result stays the markdown text it was (Glyn, P3.5-1 decision 5): JSON-encoding it would escape the
 *   block HTML these tools return, which the model then copies back into writes (BF-7). Structured data is P3.3-4's.
 */
final class MarkdownToolEnvelope
{
    /**
     * @param \Closure(mixed ...): mixed $handler
     *
     * @return \Closure(mixed ...): mixed
     */
    public static function wrap(\Closure $handler): \Closure
    {
        return static function (mixed ...$inputs) use ($handler): mixed {
            if (!(new User())->isRegistered()) {
                throw new \RuntimeException('Authentication required: Please log in to use this tool.');
            }
            try {
                $result = $handler(...$inputs);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }

            if (is_string($result) && str_starts_with(ltrim($result), '❌')) {
                return ['ok' => false, 'error' => self::errorText($result)];
            }

            return $result;
        };
    }

    /**
     * "❌ **Error approving version:** Permission denied: ..." without the marker and the bold label's asterisks.
     */
    public static function errorText(string $markdown): string
    {
        $text = trim((string) preg_replace('/^\s*❌\s*/u', '', $markdown));

        return trim((string) preg_replace('/^\*\*([^*]+)\*\*/u', '$1', $text));
    }
}
