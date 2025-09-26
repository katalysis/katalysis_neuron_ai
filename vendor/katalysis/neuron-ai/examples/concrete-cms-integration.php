<?php

namespace Application\Src\NeuronAI;

use NeuronAI\Agent;
use NeuronAI\Integrations\ConcreteCMS\ConcreteCmsLogObserver;
use Concrete\Core\Application\Application;

/**
 * Concrete CMS service provider for Neuron AI
 * 
 * This shows how to properly integrate Neuron AI with Concrete CMS
 * using our custom LogObserver.
 */
class NeuronAiServiceProvider
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function register(): void
    {
        // Bind Neuron AI agent with Concrete CMS logging
        $this->app->bind('neuron.agent', function () {
            $agent = new Agent();
            
            // Use our Concrete CMS specific log observer
            $observer = new ConcreteCmsLogObserver();
            $agent->attach($observer);
            
            return $agent;
        });
    }

    /**
     * Example: Create an agent configured for Concrete CMS
     */
    public function createAgent(string $provider = 'openai'): Agent
    {
        /** @var Agent $agent */
        $agent = $this->app->make('neuron.agent');
        
        // Configure based on Concrete CMS settings
        $config = $this->app->make('config');
        
        if ($provider === 'openai') {
            $apiKey = $config->get('app.neuron_ai.openai_key');
            // Configure OpenAI provider...
        }
        
        return $agent;
    }
}

/**
 * Usage in Concrete CMS controller:
 * 
 * class MyController extends PageController 
 * {
 *     public function someAction()
 *     {
 *         $provider = $this->app->make(NeuronAiServiceProvider::class);
 *         $agent = $provider->createAgent();
 *         
 *         $response = $agent->chat("Hello from Concrete CMS!");
 *         
 *         return new JsonResponse(['response' => $response]);
 *     }
 * }
 */
