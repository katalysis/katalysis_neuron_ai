<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use Generator;
use Katalysis\NeuronAi\PageContextTag;
use Katalysis\NeuronAi\Tools\ToolGroups;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolInterface;

/**
 * Neuron's ChatNode, sending the model only the tools offered on this request (P3.2-2), and recording each request
 * (OfferedTools). The registry still holds every tool the user's tier allows, so a call to one not offered still runs,
 * behind the same checks (approval cards, CallCheck, the loop guard).
 *
 * The groups are worked out from the conversation itself on every request, so they are the same after a pause for
 * approval and need no state of their own: the user's message and the one before it (GroupRouter), and the groups the
 * model asked for with get_tool_group since the user's message, which are offered from the next request of the turn.
 *
 * chat() and stream() are ChatNode's own, with the tools and system prompt chosen here. ChatNode is a Neuron internal:
 * ToolRoutingTest fails if a turn's request stops going through it.
 */
class GroupedChatNode extends ChatNode
{
    /**
     * @param (\Closure(SystemMessage, list<string>): SystemMessage)|null $systemPrompt the system prompt for the tools
     *     offered (the agent's toolkit guidelines for those tools only); null sends the instructions unchanged
     */
    public function __construct(private readonly ?\Closure $systemPrompt = null)
    {
    }

    protected function stream(AgentResources $resources, InferenceRequest $request, array $messages): Generator
    {
        $tools = $this->offer($resources, $messages);
        $system = $this->systemPromptFor($request->instructions, $tools);
        OfferedTools::record($system, $messages, $tools);

        return yield from $resources->provider
            ->systemPrompt($system)
            ->setTools($tools)
            ->stream(...$messages);
    }

    protected function chat(AgentResources $resources, InferenceRequest $request, array $messages): ProviderResponse
    {
        $tools = $this->offer($resources, $messages);
        $system = $this->systemPromptFor($request->instructions, $tools);
        OfferedTools::record($system, $messages, $tools);

        return $resources->provider
            ->systemPrompt($system)
            ->setTools($tools)
            ->chat(...$messages);
    }

    /**
     * @param \NeuronAI\Chat\Messages\Message[] $messages
     * @return list<ToolInterface>
     */
    private function offer(AgentResources $resources, array $messages): array
    {
        $registry = $resources->tools->all();
        if ($resources->provider instanceof RegistryAwareProvider) {
            $resources->provider->setRegistry($registry);
        }

        return self::offered($registry, $messages);
    }

    /**
     * @param ToolInterface[] $tools the registry
     * @param Message[] $messages the conversation this request sends
     * @return list<ToolInterface>
     */
    public static function offered(array $tools, array $messages): array
    {
        [$message, $previous, $requested] = self::read($messages);

        return ToolGroups::offer($tools, GroupRouter::route($message, $previous, $requested, ToolGroups::sizes($tools)));
    }

    /**
     * The user's message, the one before it (tags removed), and the groups get_tool_group asked for since the user's
     * message, in the order asked.
     *
     * @param Message[] $messages
     * @return array{string, ?string, list<string>}
     */
    private static function read(array $messages): array
    {
        $messages = array_values($messages);
        $user = [];
        $requested = [];
        foreach ($messages as $i => $message) {
            if ($message instanceof UserMessage && !$message instanceof ToolResultMessage) {
                $user[] = $i;
            }
        }
        $last = end($user);
        if ($last === false) {
            return ['', null, []];
        }
        foreach (array_slice($messages, $last + 1) as $message) {
            if (!$message instanceof ToolCallMessage) {
                continue;
            }
            foreach ($message->getToolCalls() as $call) {
                $group = $call->getInputs()['group'] ?? null;
                if ($call->getName() === ToolGroups::GET_TOOL_GROUP && is_string($group) && in_array($group, ToolGroups::ORDER, true)) {
                    $requested[] = $group;
                }
            }
        }
        $previous = count($user) > 1 ? $user[count($user) - 2] : null;

        return [
            PageContextTag::strip((string) $messages[$last]->getContent()),
            $previous === null ? null : PageContextTag::strip((string) $messages[$previous]->getContent()),
            $requested,
        ];
    }

    /**
     * @param list<ToolInterface> $tools
     */
    private function systemPromptFor(SystemMessage $instructions, array $tools): SystemMessage
    {
        if ($this->systemPrompt === null) {
            return $instructions;
        }

        return ($this->systemPrompt)($instructions, array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools));
    }
}
