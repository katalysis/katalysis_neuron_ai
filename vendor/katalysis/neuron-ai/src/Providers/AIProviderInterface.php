<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolInterface;

interface AIProviderInterface
{
    /**
     * Send predefined instruction to the LLM.
     */
    public function systemPrompt(?string $prompt): AIProviderInterface;

    /**
     * Set the tools to be exposed to the LLM.
     *
     * @param array<ToolInterface> $tools
     */
    public function setTools(array $tools): AIProviderInterface;

    /**
     * The component responsible for mapping the NeuronAI Message to the AI provider format.
     */
    public function messageMapper(): MessageMapperInterface;

    /**
     * Send a prompt to the AI agent.
     */
    public function chat(array $messages): Message;

    public function chatAsync(array $message): PromiseInterface;

    public function stream(array|string $messages, callable $executeToolsCallback): \Generator;

    public function structured(array $messages, string $class, array $response_schema): Message;

    public function setClient(Client $client): AIProviderInterface;
}
