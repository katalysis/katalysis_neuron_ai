<?php   
namespace Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai;

use Core;
use Config;
use Concrete\Core\Page\Controller\DashboardPageController;
use Katalysis\NeuronAi\Access\NeuronPermissions;
use Katalysis\NeuronAi\Settings\ApiKeySettings;
use Katalysis\NeuronAi\Settings\ModelCatalog;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;

class NeuronAi extends DashboardPageController
{

    public function view()
    {
        $this->set('title', t('AI Settings'));
        $this->set('token', $this->app->make('token'));
        $this->set('form', $this->app->make('helper/form'));
        $this->set('results', []);

        // Without the "Configure Neuron AI" task permission the page shows only a message: no form, no
        // "Key set" statuses and no settings values.
        $canConfigure = $this->canConfigure();
        $this->set('can_configure', $canConfigure);
        if (!$canConfigure) {
            return;
        }

        // API keys are never passed to the view (B-SEC-1): it only learns whether each is set.
        $settings = $this->settings();
        $this->set('key_status', $settings->keyStatus());
        foreach ($settings->plainValues() as $field => $value) {
            $this->set($field, $value);
        }
        // P3.8-1: the providers and the models the page offers.
        $this->set('provider', ModelCatalog::provider($settings->plainValues()['provider'] ?? ''));
        $this->set('providers', ModelCatalog::LABELS);
        $this->set('known_models', ModelCatalog::MODELS);
        $this->set('default_models', ModelCatalog::DEFAULT_MODELS);

        // Toolkits other packages registered that can't be built, so the assistant is running without their tools.
        $this->set('external_toolkit_skips', $this->externalToolkitSkips());
    }

    public function save()
    {
        // Using the assistant and changing its keys, models and URL are separate Concrete task permissions
        // ("Use Neuron AI assistant", "Configure Neuron AI"). Saving needs the second, as well as the CSRF token.
        if (!$this->canConfigure()) {
            $this->error->add(t('You don\'t have permission to change Neuron AI settings.'));
        } elseif (!$this->token->validate('ai.settings')) {
            $this->error->add($this->token->getErrorMessage());
        } else {
            // P3.9-4, P3.8-1: a value that can't be stored (ApiKeySettings::refusals()), or a provider chosen without its
            // key, refuses the whole form.
            $post = $this->request->request->all();
            foreach (ApiKeySettings::refusals($post) as $refusal) {
                $this->error->add(t($refusal));
            }
            if ($refusal = $this->settings()->keyRefusal($post)) {
                $this->error->add(t($refusal));
            }
        }

        if ($this->error->has()) {
            // Show the page again with the error. Nothing is saved.
            $this->view();

            return null;
        }

        $removedWithNewValue = $this->settings()->save($this->request->request->all());
        if ($removedWithNewValue === []) {
            $this->flash('success', t('AI settings have been updated.'));
        } else {
            // The dashboard shows one banner and prefers 'message' over 'success', so both sentences go in one.
            $labels = [
                'open_ai_key' => t('Open AI Key'),
                'anthropic_key' => t('Anthropic AI Key'),
                'ollama_key' => t('Ollama Key'),
            ];
            $lines = [t('AI settings have been updated.')];
            foreach ($removedWithNewValue as $field) {
                $lines[] = $labels[$field] . ': ' . t('Key removed; the new value was not saved.');
            }
            $this->flash('message', implode("\n", $lines));
        }

        return $this->buildRedirect($this->action());
    }

    /**
     * Whether the current user has the "Configure Neuron AI" task permission (the super user always does).
     * The user also needs Concrete's normal view permission on this dashboard page to open it.
     */
    protected function canConfigure(): bool
    {
        return NeuronPermissions::userHas(NeuronPermissions::CONFIGURE_HANDLE);
    }

    /**
     * Builds the registered external toolkits the way the agent does, to show which are skipped (P2.2). The agent logs
     * each skip; this page doesn't log them again.
     *
     * @return list<array{provider: string, toolkit: ?string, reason: string}>
     */
    protected function externalToolkitSkips(): array
    {
        return ExternalToolkitRegistry::build(static function (string $level, string $message): void {
        })->skipped();
    }

    protected function settings(): ApiKeySettings
    {
        return new ApiKeySettings($this->app->make('config'));
    }

}