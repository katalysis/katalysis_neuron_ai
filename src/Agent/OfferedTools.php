<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\OpenAI\ToolMapper;
use NeuronAI\Tools\ToolInterface;

/**
 * What each request to the model offered (P3.2-2): the tools, by name and in order, and an estimate of its input
 * tokens. GroupedChatNode records every request; the scenario runner takes them after each turn, so a run shows how
 * many tools each request had and what the first request of a turn cost, with or without a real model.
 *
 * The estimate is Neuron's own (TokenCounter, 4 characters a token) over the system prompt and the messages, plus the
 * tools as the OpenAI provider sends them (ToolMapper's JSON), at the same 4 characters a token. It is the same count
 * for a fake run and a paid one, so before and after compare; it is not the provider's usage.
 *
 * Only the latest requests are kept (MAX), so a long-lived process doesn't grow.
 */
final class OfferedTools
{
    public const MAX = 200;

    /** @var list<array{tools: list<string>, tokens: int}> */
    private static array $requests = [];

    /**
     * @param Message[] $messages
     * @param ToolInterface[] $tools
     */
    public static function record(?SystemMessage $system, array $messages, array $tools): void
    {
        self::$requests[] = [
            'tools' => array_values(array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools)),
            'tokens' => self::estimate($system, $messages, $tools),
        ];
        if (count(self::$requests) > self::MAX) {
            self::$requests = array_slice(self::$requests, -self::MAX);
        }
    }

    /**
     * The requests recorded since the last take(), oldest first; the record is emptied.
     *
     * @return list<array{tools: list<string>, tokens: int}>
     */
    public static function take(): array
    {
        $requests = self::$requests;
        self::$requests = [];

        return $requests;
    }

    /**
     * @param Message[] $messages
     * @param ToolInterface[] $tools
     */
    public static function estimate(?SystemMessage $system, array $messages, array $tools): int
    {
        $counter = new TokenCounter();
        $tokens = $system === null ? 0 : $counter->count($system);
        foreach ($messages as $message) {
            $tokens += $counter->count($message);
        }
        $schemas = json_encode((new ToolMapper())->map(array_values($tools)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $tokens + (int) ceil(mb_strlen((string) $schemas) / 4);
    }
}
