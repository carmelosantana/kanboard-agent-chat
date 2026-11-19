<?php // Searches from the chat page land on the task list. ?>
<?= $this->projectHeader->render($project, 'TaskListController', 'show') ?>

<div class="agentchat-room">
    <div class="agentchat-room__main">
        <?= new \ChatParts\View\Conversation($host, $messages, $host->url('poll', ['project_id' => $project['id']]), $afterId) ?>
        <?php if ($canPost): ?>
            <?= new \ChatParts\View\Composer($host, $host->url('send', ['project_id' => $project['id']]), ['canAsk' => $canAsk, 'allowImages' => false, 'stopUrl' => $host->url('stop', ['project_id' => $project['id']])]) ?>
        <?php else: ?>
            <?= new \ChatParts\View\Notice($host, 'info', t('You can read this room but not post in it.')) ?>
        <?php endif ?>
    </div>
    <?= $this->render('AgentChat:room/rail', ['host' => $host, 'members' => $members]) ?>
</div>
