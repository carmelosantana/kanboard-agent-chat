<?php if (! empty($agentchat_can_manage)): ?>
<li <?= $this->app->checkMenuSelection('SettingsController', 'project', 'AgentChat') ?>>
    <?= $this->url->link(t('Chat'), 'SettingsController', 'project', ['project_id' => $project['id'], 'plugin' => 'AgentChat']) ?>
</li>
<?php endif ?>
