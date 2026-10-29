<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use Generator;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolInterface;

/**
 * Neuron's ChatNode, sending the model only the tools offered on this request (P3.2-2), and recording each request
 * (OfferedTools). The registry still holds every tool the user's tier allows, so a call to one not offered still runs,
 * behind the same checks.
 *
 * chat() and stream() are ChatNode's own, with the tool list chosen here. ChatNode is a Neuron internal:
 * ToolRoutingTest fails if a turn's request stops going through it.
 */
class GroupedChatNode extends ChatNode
{
    protected function stream(AgentResources $resources, InferenceRequest $request, array $messages): Generator
    {
        $tools = $this->offered($resources, $messages);
        OfferedTools::record($request->instructions, $messages, $tools);

        return yield from $resources->provider
            ->systemPrompt($request->instructions)
            ->setTools($tools)
            ->stream(...$messages);
    }

    protected function chat(AgentResources $resources, InferenceRequest $request, array $messages): ProviderResponse
    {
        $tools = $this->offered($resources, $messages);
        OfferedTools::record($request->instructions, $messages, $tools);

        return $resources->provider
            ->systemPrompt($request->instructions)
            ->setTools($tools)
            ->chat(...$messages);
    }

    /**
     * @param \NeuronAI\Chat\Messages\Message[] $messages
     * @return list<ToolInterface>
     */
    protected function offered(AgentResources $resources, array $messages): array
    {
        return array_values($resources->tools->all());
    }
}
