<li <?= $this->app->checkMenuSelection('ChatSettingsController', 'limits', 'AgentChat') ?>>
    <?= $this->url->link(t('Chat limits'), 'ChatSettingsController', 'limits', ['plugin' => 'AgentChat']) ?>
</li>
