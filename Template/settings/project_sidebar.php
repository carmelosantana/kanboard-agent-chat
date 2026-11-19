<?php if (! empty($agentchat_can_manage)): ?>
<li <?= $this->app->checkMenuSelection('ChatSettingsController', 'project', 'AgentChat') ?>>
    <?= $this->url->link(t('Chat'), 'ChatSettingsController', 'project', ['project_id' => $project['id'], 'plugin' => 'AgentChat']) ?>
</li>
<?php endif ?>
