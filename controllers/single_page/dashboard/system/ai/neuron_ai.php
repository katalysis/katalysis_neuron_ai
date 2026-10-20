<?php   
namespace Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai;

use Core;
use Config;
use Concrete\Core\Page\Controller\DashboardPageController;
use Katalysis\NeuronAi\Settings\ApiKeySettings;

class NeuronAi extends DashboardPageController
{

    public function view()
    {
        $this->set('title', t('AI Settings'));
        $this->set('token', $this->app->make('token'));
        $this->set('form', $this->app->make('helper/form'));

        // API keys are never passed to the view (B-SEC-1): it only learns whether each is set.
        $settings = $this->settings();
        $this->set('key_status', $settings->keyStatus());
        foreach ($settings->plainValues() as $field => $value) {
            $this->set($field, $value);
        }

        $this->set('results', []);
    }

    public function save() 
	{
		if (!$this->token->validate('ai.settings')) {
            $this->error->add($this->token->getErrorMessage());
        }

        if (!$this->error->has()) {
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
        }
        return $this->buildRedirect($this->action());
    }

    protected function settings(): ApiKeySettings
    {
        return new ApiKeySettings($this->app->make('config'));
    }

}