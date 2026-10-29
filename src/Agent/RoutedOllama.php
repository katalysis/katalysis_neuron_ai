<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Agent;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\Ollama\Ollama;

/**
 * Neuron's Ollama provider, chosen in the settings (P3.8-1), which also accepts a call to a tool of the registry that
 * this request didn't offer (AcceptsRegistryTools). Neuron's Ollama takes no key; the optional Ollama Key setting is
 * sent as a bearer token, for an Ollama server behind a proxy that asks for one.
 */
class RoutedOllama extends Ollama implements RegistryAwareProvider
{
    use AcceptsRegistryTools;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(string $url, string $model, array $parameters = [], ?HttpClientInterface $httpClient = null, ?string $key = null)
    {
        parent::__construct($url, $model, $parameters, $httpClient);
        if ($key !== null && $key !== '') {
            $this->httpHeaders['Authorization'] = 'Bearer ' . $key;
        }
    }
}
