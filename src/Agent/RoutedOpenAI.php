<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\ToolInterface;

/**
 * Neuron's OpenAI provider, which also accepts a call to a tool of the registry that this request didn't offer
 * (P3.2-2, plans/P3.2-2.md §3).
 *
 * The provider sends the offered tools (GroupedChatNode) and checks every call the model makes against the same list:
 * a call to any other tool fails the whole turn. The model can still name one, from an earlier turn's history. Such a
 * call is taken as a call to the registry's tool, so it runs as any call does (approval card, CallCheck, the loop
 * guard). Without a registry (setRegistry()), it behaves as Neuron's.
 */
class RoutedOpenAI extends OpenAI
{
    /** @var ToolInterface[] */
    private array $registry = [];

    /**
     * @param ToolInterface[] $tools every tool the agent may run (its registry)
     */
    public function setRegistry(array $tools): static
    {
        $this->registry = $tools;

        return $this;
    }

    public function findTool(string $name): ToolInterface
    {
        try {
            return parent::findTool($name);
        } catch (ProviderException $e) {
            foreach ($this->registry as $tool) {
                if ($tool instanceof ToolInterface && $tool->getName() === $name) {
                    return clone $tool;
                }
            }

            throw $e;
        }
    }
}
