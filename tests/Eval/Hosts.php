<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

/**
 * BF-12 (B-TRU-9): the hosts a text names, and the ones a reply names that the run never gave it. Found in BF-11's
 * check 4: a reply gave https://your-domain/application/files/… for an image the tool returned only as a path.
 *
 * A host is the host of an http(s) or protocol-relative URL, a bare www. host, or a known placeholder host written as
 * a link (followed by "/" or a port). E-mail addresses, relative paths and file names are not hosts. Hosts are
 * lower-cased, without the port.
 */
final class Hosts
{
    /** Placeholder hosts a model writes when it has no real one. Matched only when written as a link. */
    private const PLACEHOLDERS = 'your-?domain|your-?site|your-?website|my-?site|example\.(?:com|org|net)|(?:domain|website|site)\.com';

    private const LABEL = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';

    /**
     * @return list<string> the hosts in the text, each once, in order of first appearance
     */
    public static function inText(string $text): array
    {
        $host = self::LABEL . '(?:\.' . self::LABEL . ')*';
        $patterns = [
            // http(s)://host, or //host where "//" doesn't follow a word character, ":" or "/".
            '~(?:\bhttps?:|(?<![\w:/]))//(' . $host . ')~i',
            '~(?<![\w.@/-])(www\.' . $host . ')~i',
            '~(?<![\w.@/-])((?:' . self::LABEL . '\.)*(?:' . self::PLACEHOLDERS . ')(?:\.' . self::LABEL . ')*)(?=/|:\d)~i',
        ];

        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[1] as [$match, $offset]) {
                    $found[$offset] ??= strtolower($match);
                }
            }
        }
        ksort($found);

        return array_values(array_unique($found));
    }

    /**
     * The hosts the run gave the model: every tool result, and the person's messages. Tool inputs are not a source:
     * the model writes them.
     *
     * @param list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}> $toolCalls
     * @param list<string> $prompts
     *
     * @return list<string>
     */
    public static function allowed(array $toolCalls, array $prompts): array
    {
        $hosts = [];
        foreach ($toolCalls as $call) {
            // A JSON result may escape its slashes.
            array_push($hosts, ...self::inText(str_replace('\\/', '/', (string) $call['result'])));
        }
        foreach ($prompts as $prompt) {
            array_push($hosts, ...self::inText($prompt));
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param list<array{name: string, inputs: array<string, mixed>, result: string, ok: bool}> $toolCalls
     * @param list<string> $prompts
     *
     * @return list<string> the reply's hosts that no tool result and no message of the person's had
     */
    public static function invented(string $reply, array $toolCalls, array $prompts): array
    {
        return array_values(array_diff(self::inText($reply), self::allowed($toolCalls, $prompts)));
    }
}
