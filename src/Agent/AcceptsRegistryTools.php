<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\ToolInterface;

/**
 * For Neuron's providers (P3.2-2, P3.8-1): a call to a tool of the registry that this request didn't offer is taken
 * as a call to the registry's tool, so it runs as any call does (approval card, CallCheck, the loop guard), instead of
 * failing the whole turn. Neuron's provider checks every call against the tools it was sent (GroupedChatNode sends only
 * the offered ones), and the model can still name another from an earlier turn's history. Without a registry
 * (setRegistry()), the provider behaves as Neuron's.
 *
 * Used by RoutedOpenAI, RoutedAnthropic and RoutedOllama, whose parent's findTool() comes from HandleWithTools.
 */
trait AcceptsRegistryTools
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
