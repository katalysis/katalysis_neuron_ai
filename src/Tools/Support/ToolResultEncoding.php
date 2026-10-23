<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Support;

use Katalysis\NeuronAi\Tools\Utilities\HtmlIntegrity;

/**
 * How a tool's array result is written for the model (BF-7, Glyn's browser check 6). Neuron's own encoding escapes
 * every slash ("<\/h2>") and keeps CR LF as "\r\n"; the model copied that text back into a write. Here HTML has no
 * line breaks (HtmlIntegrity::withoutLineBreaks()), other text has LF only, and slashes and Unicode are not escaped.
 */
final class ToolResultEncoding
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @param array<mixed> $result
     */
    public static function encode(array $result): string
    {
        return json_encode(self::prepare($result), self::FLAGS);
    }

    public static function prepare(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'prepare'], $value);
        }
        if (!is_string($value) || !preg_match('/[\r\n]/', $value)) {
            return $value;
        }

        return HtmlIntegrity::hasTags($value)
            ? HtmlIntegrity::withoutLineBreaks($value)
            : str_replace(["\r\n", "\r"], "\n", $value);
    }
}
