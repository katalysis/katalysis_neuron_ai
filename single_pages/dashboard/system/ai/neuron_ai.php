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
// Without the "Configure Neuron AI" task permission the page shows only a message: no form, no key statuses.
// The server refuses a save from such a user whatever the page shows.
$canConfigure = !empty($can_configure);
if (!$canConfigure) {
    ?>
    <div class="alert alert-warning">
        <?= t('You don\'t have permission to change Neuron AI settings.') ?>
    </div>
    <?php
    return;
}
// External toolkits that failed to build (P2.2): the assistant runs without their tools until the package is fixed.
$externalToolkitSkips = isset($external_toolkit_skips) && is_array($external_toolkit_skips) ? $external_toolkit_skips : [];
if ($externalToolkitSkips !== []) {
    ?>
    <div class="alert alert-warning" id="neuron-external-toolkit-skips">
        <p><?= t('These toolkits from other packages could not be loaded. The assistant is running without their tools.') ?></p>
        <ul class="mb-0">
            <?php foreach ($externalToolkitSkips as $skip) { ?>
                <li>
                    <?= h($skip['toolkit'] ?? t('(the package\'s toolkit provider)')) ?>
                    (<?= h(t('package key: %s', $skip['provider'])) ?>): <?= h($skip['reason']) ?>
                </li>
            <?php } ?>
        </ul>
    </div>
    <?php
}
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
                            <?php // P3.8-1: the provider the assistant uses, and the models known for each. ?>
                            <div class="form-group">
                                <label class="form-label" for="provider"><?php echo t('AI Provider'); ?></label>
                                <select class="form-select" name="provider" id="provider">
                                    <?php foreach (($providers ?? []) as $handle => $label) { ?>
                                        <option value="<?= h($handle) ?>" <?= ($provider ?? 'openai') === $handle ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php } ?>
                                </select>
                                <div class="form-text"><?= t('The chosen provider needs its key. A model left blank uses the provider\'s default.') ?></div>
                            </div>
                            <?php foreach (($known_models ?? []) as $handle => $models) {
                                if (!is_array($models)) {
                                    continue;
                                } ?>
                                <datalist id="models_<?= h($handle) ?>">
                                    <?php foreach ($models as $model) { ?>
                                        <option value="<?= h($model) ?>"></option>
                                    <?php } ?>
                                </datalist>
                            <?php } ?>
                            <?php $renderKeyField('open_ai_key', t('Open AI Key')); ?>
                            <div class="form-group">
                                <label class="form-label" for="open_ai_model"><?php echo t('Open AI Model'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="open_ai_model"
                                    id="open_ai_model" list="models_openai" placeholder="<?= h($default_models['openai'] ?? '') ?>" value="<?= h($open_ai_model ?? '') ?>" />
                            </div>
                            <?php $renderKeyField('anthropic_key', t('Anthropic AI Key')); ?>
                            <div class="form-group">
                                <label class="form-label"
                                    for="anthropic_model"><?php echo t('Anthropic AI Model'); ?></label>
                                <input class="form-control ccm-input-text" type="text" name="anthropic_model"
                                    id="anthropic_model" list="models_anthropic" placeholder="<?= h($default_models['anthropic'] ?? '') ?>"
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
                <?php // P3.9-2 (B-PII-5): how long chats are kept. ?>
                <fieldset class="mb-5">
                    <legend><?php echo t('Chat retention'); ?></legend>
                    <div class="form-group">
                        <label class="form-label" for="chat_retention_days"><?php echo t('Keep chats for (days)'); ?></label>
                        <input class="form-control ccm-input-text" type="number" min="1" max="3650" step="1" name="chat_retention_days"
                            id="chat_retention_days" placeholder="<?= h((string) ($retention_default ?? 90)) ?>"
                            value="<?= h($chat_retention_days ?? '') ?>" />
                        <div class="form-text"><?= t('Chats with no activity for this long are deleted, with their messages, approval records and the assistant\'s log lines, by the "Purge old Neuron AI chats" task and once a day when the chat is used. Blank is %s days.', (int) ($retention_default ?? 90)) ?></div>
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

<?php // P3.9-2: the admin's purge, two posts: count, then confirm on this page (no browser dialog). ?>
<div class="mt-5 mb-5" id="neuron-chat-purge">
    <h3><?php echo t('Delete chats now'); ?></h3>
    <?php if (!empty($purge_confirmation)) {
        $counts = $purge_confirmation['counts']; ?>
        <div class="alert alert-warning" role="alert">
            <p><strong><?= h($purge_confirmation['scope'] === 'all'
                ? t('This deletes every chat: %s chats (%s messages) from %s users.', $counts['chats'], $counts['messages'], $counts['users'])
                : t('This deletes %s chats (%s messages) from %s users with no activity for %s days.', $counts['chats'], $counts['messages'], $counts['users'], (int) ($retention_days ?? 90))) ?></strong></p>
            <?php if ($counts['waiting'] > 0) { ?>
                <p><?= h(t('%s of them have a change waiting for approval; it won\'t be made.', $counts['waiting'])) ?></p>
            <?php } ?>
            <p><?= t('This can\'t be undone.') ?></p>
            <form method="post" action="<?= $controller->action('purge_chats') ?>" class="d-inline">
                <?php $token->output('ai.chat_purge'); ?>
                <input type="hidden" name="scope" value="<?= h($purge_confirmation['scope']) ?>" />
                <input type="hidden" name="confirm_cutoff" value="<?= h($purge_confirmation['cutoff']) ?>" />
                <button type="submit" class="btn btn-danger"><?= t('Confirm delete') ?></button>
            </form>
            <a class="btn btn-secondary" href="<?= $controller->action('') ?>"><?= t('Cancel') ?></a>
        </div>
    <?php } else { ?>
        <form method="post" action="<?= $controller->action('purge_chats') ?>" class="d-inline">
            <?php $token->output('ai.chat_purge'); ?>
            <input type="hidden" name="scope" value="older" />
            <button type="submit" class="btn btn-outline-danger"><?= t('Purge chats older than %s days', (int) ($retention_days ?? 90)) ?></button>
        </form>
        <form method="post" action="<?= $controller->action('purge_chats') ?>" class="d-inline">
            <?php $token->output('ai.chat_purge'); ?>
            <input type="hidden" name="scope" value="all" />
            <button type="submit" class="btn btn-outline-danger"><?= t('Delete all chats') ?></button>
        </form>
        <div class="form-text"><?= t('Each button first shows how many chats it would delete; nothing is deleted until you confirm.') ?></div>
    <?php } ?>
</div>



