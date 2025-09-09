<?php
defined('C5_EXECUTE') or die('Access Denied.');

use \NeuronAI\Chat\Messages\UserMessage;
use \NeuronAI\Observability\AgentMonitoring;
use Concrete\Core\Support\Facade\Package;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Page\Controller\DashboardPageController;

$token = \Core::make('token');

/**
 * @var Packages\KatalysisAi\Controller\SinglePage\Dashboard\System\Ai\KatalysisNeuronAi $controller
 * @var Concrete\Core\Form\Service\Form $form
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var int $segmentMaxLength
 */


$app = Application::getFacadeApplication();
$form = $app->make('helper/form');
?>

<form method="post" enctype="multipart/form-data" action="<?= $controller->action('save') ?>">
    <?php $token->output('ai.settings'); ?>
    <div id="ccm-dashboard-content-inner">
        <div class="row justify-content-between mb-5">
            <div class="col">
                <fieldset class="mb-5">
                    <legend><?php echo t('Basic Settings'); ?></legend>
                    <div class="row">
                        <div class="col">
                            <div class="form-group">
                                <label class="form-label" for="open_ai_key"><?php echo t('Open AI Key'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="open_ai_key"
                                    id="open_ai_key" value="<?= isset($open_ai_key) ? $open_ai_key : '' ?>" />
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="open_ai_model"><?php echo t('Open AI Model'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="open_ai_model"
                                    id="open_ai_model" value="<?= isset($open_ai_model) ? $open_ai_model : '' ?>" />
                            </div>
                            <div class="form-group">
                                <label class="form-label"
                                    for="anthropic_key"><?php echo t('Anthropic AI Key'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="anthropic_key"
                                    id="anthropic_key"
                                    value="<?= isset($anthropic_key) ? $anthropic_key : '' ?>" />
                            </div>
                            <div class="form-group">
                                <label class="form-label"
                                    for="anthropic_model"><?php echo t('Anthropic AI Model'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="anthropic_model"
                                    id="anthropic_model"
                                    value="<?= isset($anthropic_model) ? $anthropic_model : '' ?>" />
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="ollama_key"><?php echo t('Ollama Key'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="ollama_key"
                                    id="ollama_key" value="<?= isset($ollama_key) ? $ollama_key : '' ?>" />
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="ollama_url"><?php echo t('Ollama URL'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="ollama_url"
                                    id="ollama_url" value="<?= isset($ollama_url) ? $ollama_url : '' ?>" />
                            </div>
                            <div class="form-group">
                                <label class="form-label"
                                    for="ollama_model"><?php echo t('Ollama Model'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="ollama_model"
                                    id="ollama_model"
                                    value="<?= isset($ollama_model) ? $ollama_model : '' ?>" />
                            </div>
                        </div>
                    </div>
                </fieldset>
            </div>
        </div>
    </div>
    
    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <div class="float-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save" aria-hidden="true"></i> <?php echo t('Save'); ?>
                </button>
            </div>
        </div>
    </div>
</form>



