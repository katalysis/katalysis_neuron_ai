<?php   
namespace Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai;

use Core;
use Config;
use Concrete\Core\Page\Controller\DashboardPageController;

class NeuronAi extends DashboardPageController
{

    public function view()
    {
        $this->set('title', t('AI Settings'));
        $this->set('token', $this->app->make('token'));
        $this->set('form', $this->app->make('helper/form'));

        $config = $this->app->make('config');
        $this->set('open_ai_key', (string) $config->get('katalysis.ai.open_ai_key'));
        $this->set('open_ai_model', (string) $config->get('katalysis.ai.open_ai_model'));
        $this->set('anthropic_key', (string) $config->get('katalysis.ai.anthropic_key'));
        $this->set('anthropic_model', (string) $config->get('katalysis.ai.anthropic_model'));
        $this->set('ollama_key', (string) $config->get('katalysis.ai.ollama_key'));
        $this->set('ollama_url', (string) $config->get('katalysis.ai.ollama_url'));
        $this->set('ollama_model', (string) $config->get('katalysis.ai.ollama_model'));

        $this->set('results', []);
    }

    public function save() 
	{
		if (!$this->token->validate('ai.settings')) {
            $this->error->add($this->token->getErrorMessage());
        }

        if (!$this->error->has()) {
            $config = $this->app->make('config');
            $config->save('katalysis.ai.open_ai_key', (string) $this->post('open_ai_key'));
            $config->save('katalysis.ai.open_ai_model', (string) $this->post('open_ai_model'));
            $config->save('katalysis.ai.anthropic_key', (string) $this->post('anthropic_key'));
            $config->save('katalysis.ai.anthropic_model', (string) $this->post('anthropic_model'));
            $config->save('katalysis.ai.ollama_key', (string) $this->post('ollama_key'));
            $config->save('katalysis.ai.ollama_url', (string) $this->post('ollama_url'));
            $config->save('katalysis.ai.ollama_model', (string) $this->post('ollama_model'));
            $this->flash('success', t('AI settings have been updated.'));
        }
        return $this->buildRedirect($this->action());
    }

}