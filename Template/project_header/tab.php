<?php if ($agentchat_can_view): ?>
<li <?= $this->app->checkMenuSelection('RoomController', '', 'AgentChat') ?>>
    <?= $this->url->icon('comments', t('Chat'), 'RoomController', 'show', ['project_id' => $project['id'], 'plugin' => 'AgentChat'], false, 'view-agentchat') ?>
</li>
<?php endif ?>
