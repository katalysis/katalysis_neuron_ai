<?php

namespace Concrete\Package\KatalysisNeuronAi\Controller;

use Concrete\Core\Controller\AbstractController;
use Concrete\Core\Http\ResponseFactory;
use Concrete\Core\Package\Package;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use NeuronAI\Agent\Agent;

class Diagnostics extends AbstractController
{
    public function status()
    {
        $app = Application::getFacadeApplication();
        $responseFactory = $app->make(ResponseFactory::class);
        $user = new User();

        if (!ChatAccess::canUse()) {
            return $responseFactory->json([
                'success' => false,
                'error' => 'Access denied.',
            ], 403);
        }

        $package = Package::getByHandle('katalysis_neuron_ai');
        $checks = [
            'package_found' => (bool) $package,
            'neuron_agent_class_exists' => class_exists(Agent::class),
            'concrete_cms_agent_class_exists' => class_exists(ConcreteCmsAgent::class),
            'pages_toolkit_class_exists' => class_exists(PagesToolkit::class),
            'openai_key_configured' => trim((string) Config::get('katalysis.ai.open_ai_key', '')) !== '',
            'openai_model' => (string) Config::get('katalysis.ai.open_ai_model', 'gpt-4o'),
        ];

        $agentCheck = [
            'ok' => false,
            'error' => null,
        ];

        if ($checks['concrete_cms_agent_class_exists']) {
            try {
                new ConcreteCmsAgent();
                $agentCheck['ok'] = true;
            } catch (\Throwable $throwable) {
                $agentCheck['error'] = SecretRedactor::redact($throwable->getMessage());
            }
        }

        return $responseFactory->json([
            'success' => true,
            'package' => [
                'handle' => 'katalysis_neuron_ai',
                'installed' => (bool) $package,
                'version' => $package ? $package->getPackageVersion() : null,
            ],
            'user' => [
                'id' => $user->getUserID(),
                'is_registered' => $user->isRegistered(),
            ],
            'routes' => [
                'diagnostics_status' => '/ccm/system/katalysis_neuron_ai/diagnostics/status',
                'chat_send_message' => [
                    'path' => '/ccm/system/katalysis_neuron_ai/chat/send_message',
                    'method' => 'POST',
                ],
            ],
            'checks' => $checks,
            'agent_instantiation' => $agentCheck,
        ]);
    }
}