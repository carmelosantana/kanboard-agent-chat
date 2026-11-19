<div class="page-header">
    <h2><?= t('Chat') ?></h2>
</div>
<?php if (! $botReady): ?>
    <p class="alert alert-info"><?= t('The project bot is unavailable: AiConnector is missing, too old, or has no AI provider ready. The chat works for people and agents.') ?></p>
<?php endif ?>
<form method="post" action="<?= $this->url->href('ChatSettingsController', 'saveProject', ['project_id' => $project['id'], 'plugin' => 'AgentChat']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <fieldset>
        <legend><?= t('Project bot') ?></legend>
        <?= $this->form->checkbox('bot_enabled', t('The project bot answers @bot and the task drawer'), 1, (bool) $values['bot_enabled']) ?>

        <?= $this->form->label(t('Who may ask the bot'), 'ask_role') ?>
        <?= $this->form->select('ask_role', [
            'managers' => t('Managers only'),
            'members' => t('Members and managers'),
            'everyone' => t('Everyone with access, viewers included'),
        ], $values, $errors) ?>

        <?php if ($profiles !== null): ?>
            <?= $this->form->label(t('AI profile'), 'ai_profile') ?>
            <?= $this->form->select('ai_profile', ['' => t('AiConnector\'s default')] + $profiles, $values, $errors) ?>
        <?php endif ?>

        <?= $this->form->checkbox('bot_images', t('Send images to the bot'), 1, (bool) $values['bot_images']) ?>
        <p class="form-help"><?= t('Images always stay in the chat for people; with this off the bot answers from the text alone.') ?></p>
    </fieldset>

    <?= $this->modal->submitButtons() ?>
</form>
