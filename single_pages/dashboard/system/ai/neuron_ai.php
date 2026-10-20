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

// API keys are never rendered (B-SEC-1): only whether each is set. The input is always
// empty; a blank save keeps the key, a new value replaces it, "Remove key" clears it.
$keyStatus = isset($key_status) && is_array($key_status) ? $key_status : [];
$renderKeyField = static function (string $field, string $label) use ($keyStatus): void {
    $isSet = !empty($keyStatus[$field]);
    ?>
    <div class="form-group">
        <label class="form-label" for="<?= $field ?>"><?= h($label) ?></label>
        <input class="form-control ccm-input-text" type="password" name="<?= $field ?>" id="<?= $field ?>"
            value="" autocomplete="new-password"
            placeholder="<?= h($isSet ? t('Leave blank to keep the saved key') : t('Not set')) ?>" />
        <div class="form-text"><?= $isSet ? t('Key set') : t('Not set') ?></div>
        <?php if ($isSet) { ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remove_<?= $field ?>" id="remove_<?= $field ?>" value="1" />
                <label class="form-check-label" for="remove_<?= $field ?>"><?= t('Remove key') ?></label>
            </div>
        <?php } ?>
    </div>
    <?php
};
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
                            <?php $renderKeyField('open_ai_key', t('Open AI Key')); ?>
                            <div class="form-group">
                                <label class="form-label" for="open_ai_model"><?php echo t('Open AI Model'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="open_ai_model"
                                    id="open_ai_model" value="<?= h($open_ai_model ?? '') ?>" />
                            </div>
                            <?php $renderKeyField('anthropic_key', t('Anthropic AI Key')); ?>
                            <div class="form-group">
                                <label class="form-label"
                                    for="anthropic_model"><?php echo t('Anthropic AI Model'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="anthropic_model"
                                    id="anthropic_model"
                                    value="<?= h($anthropic_model ?? '') ?>" />
                            </div>
                            <?php $renderKeyField('ollama_key', t('Ollama Key')); ?>
                            <div class="form-group">
                                <label class="form-label" for="ollama_url"><?php echo t('Ollama URL'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="ollama_url"
                                    id="ollama_url" value="<?= h($ollama_url ?? '') ?>" />
                            </div>
                            <div class="form-group">
                                <label class="form-label"
                                    for="ollama_model"><?php echo t('Ollama Model'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="ollama_model"
                                    id="ollama_model"
                                    value="<?= h($ollama_model ?? '') ?>" />
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



