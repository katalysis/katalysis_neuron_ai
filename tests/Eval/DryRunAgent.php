<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Eval;

use Katalysis\NeuronAi\ConcreteCmsAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

/**
 * The agent for the runner's free dry run (run_scenarios.php --fake, P2.6): the real ConcreteCmsAgent with Neuron's
 * FakeAIProvider as the model. Every turn asks for get_current_page_context once, then replies DRY_RUN_REPLY, each
 * with made-up usage. No AI provider is called and no API key is needed.
 *
 * The scenario checks mostly fail on a dry run, because the replies are fake. What it shows is that the runner works:
 * every scenario runs to the end, the tool call and its result are recorded, usage adds up, the chats are stored.
 */
final class DryRunAgent
{
    public const DRY_RUN_REPLY = 'Dry run reply.';
    public const MODEL = 'fake (dry run, no provider call)';
    /** Made-up usage per turn: the tool call request, then the reply request. */
    public const USAGE = [[100, 10], [200, 20]];

    public static function make(): ConcreteCmsAgent
    {
        $call = new ToolCallMessage(null, [new ToolCall('get_current_page_context', 'dry_' . bin2hex(random_bytes(4)), [])]);
        $call->setUsage(new Usage(self::USAGE[0][0], self::USAGE[0][1]));
        $reply = new AssistantMessage(self::DRY_RUN_REPLY);
        $reply->setUsage(new Usage(self::USAGE[1][0], self::USAGE[1][1]));

        return (new ConcreteCmsAgent())->setAiProvider(new FakeAIProvider($call, $reply));
    }
}
