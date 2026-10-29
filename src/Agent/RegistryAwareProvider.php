<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use NeuronAI\Tools\ToolInterface;

/**
 * A provider GroupedChatNode gives the registry to (AcceptsRegistryTools), so a call to a tool not offered still runs.
 */
interface RegistryAwareProvider
{
    /**
     * @param ToolInterface[] $tools
     */
    public function setRegistry(array $tools): static;
}
