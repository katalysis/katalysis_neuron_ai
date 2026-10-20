<?php   
namespace Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai;

use Core;
use Config;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Permission\Checker;
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

        $this->set('can_edit', $this->canEditSettings());
        $this->set('results', []);
    }

    public function save()
    {
        // Viewing the page is what lets a user use the chat (B-ACC-1), so it must not be enough to change
        // the keys, models or URL: saving needs edit permission on the page, as well as the CSRF token.
        if (!$this->canEditSettings()) {
            $this->error->add(t('You do not have permission to change these settings.'));
        } elseif (!$this->token->validate('ai.settings')) {
            $this->error->add($this->token->getErrorMessage());
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
     * Whether the current user may change the settings: "Edit Page Properties" on this page.
     * Everyone who can view the page can open it and see whether each key is set.
     */
    protected function canEditSettings(): bool
    {
        $page = $this->getPageObject();
        if (!$page || $page->isError()) {
            return false;
        }

        return (bool) (new Checker($page))->canEditPageProperties();
    }

    protected function settings(): ApiKeySettings
    {
        return new ApiKeySettings($this->app->make('config'));
    }

}