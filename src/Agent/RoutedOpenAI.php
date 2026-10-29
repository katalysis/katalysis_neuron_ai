<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use NeuronAI\Providers\OpenAI\OpenAI;

/**
 * Neuron's OpenAI provider, which also accepts a call to a tool of the registry that this request didn't offer
 * (P3.2-2, plans/P3.2-2.md §3; AcceptsRegistryTools).
 */
class RoutedOpenAI extends OpenAI implements RegistryAwareProvider
{
    use AcceptsRegistryTools;
}
