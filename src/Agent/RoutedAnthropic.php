<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use NeuronAI\Providers\Anthropic\Anthropic;

/**
 * Neuron's Anthropic provider, chosen in the settings (P3.8-1), which also accepts a call to a tool of the registry
 * that this request didn't offer (AcceptsRegistryTools).
 */
class RoutedAnthropic extends Anthropic implements RegistryAwareProvider
{
    use AcceptsRegistryTools;
}
