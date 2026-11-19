<div class="page-header">
    <h2><?= t('Chat limits') ?></h2>
</div>
<p class="alert alert-info"><?= t('Each streaming answer holds one PHP worker. Keep the instance limit below your PHP-FPM pm.max_children.') ?></p>
<form method="post" action="<?= $this->url->href('SettingsController', 'saveLimits', ['plugin' => 'AgentChat']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <fieldset>
        <legend><?= t('Project bot') ?></legend>
        <?php foreach (\Kanboard\Plugin\AgentChat\Model\LimitsSettings::FIELDS as $key => $label): ?>
            <?= $this->form->label(t($label), $key) ?>
            <?= $this->form->number($key, $values, $errors, ['min="1"', 'step="1"', 'required']) ?>
        <?php endforeach ?>
    </fieldset>

    <?= $this->modal->submitButtons() ?>
</form>
