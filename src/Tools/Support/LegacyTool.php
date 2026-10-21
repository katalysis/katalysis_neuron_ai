<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Support;

/**
 * The Neuron v3 tool surface on top of CallableTool: `new LegacyTool(name, description)`, addProperty(),
 * setCallable(). Lets the agent boot on Neuron 4 without converting every tool by hand.
 *
 * P2.1 spike only. Never merged: P2.3 converts the tools and removes this class.
 */
class LegacyTool extends CallableTool
{
    public function __construct(string $name, ?string $description = null)
    {
        parent::__construct($name, $description);
    }

    public function setCallable(callable $callback): static
    {
        return $this->setHandler($callback);
    }
}
