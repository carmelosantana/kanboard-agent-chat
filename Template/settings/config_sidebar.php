<li <?= $this->app->checkMenuSelection('SettingsController', 'limits', 'AgentChat') ?>>
    <?= $this->url->link(t('Chat limits'), 'SettingsController', 'limits', ['plugin' => 'AgentChat']) ?>
</li>
