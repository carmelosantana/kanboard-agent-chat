<div class="page-header">
    <h2><?= $this->text->e($doc['title']) ?></h2>
    <ul>
        <li><?= $this->url->icon('comments', t('Back to the chat'), 'RoomController', 'show', ['project_id' => $project['id'], 'plugin' => 'AgentChat']) ?></li>
    </ul>
</div>

<ul class="agentchat-knowledge__meta">
    <li><strong><?= t('Source:') ?></strong> <?= $this->text->e($doc['source']) ?></li>
    <?php if ((string) $doc['author'] !== ''): ?>
        <li><strong><?= t('Author:') ?></strong> <?= $this->text->e($doc['author']) ?></li>
    <?php endif ?>
    <?php if ((int) $doc['occurred_at'] > 0): ?>
        <li><strong><?= t('Date:') ?></strong> <?= $this->dt->datetime((int) $doc['occurred_at']) ?></li>
    <?php endif ?>
    <?php if ($url !== ''): ?>
        <li><strong><?= t('Original:') ?></strong> <a href="<?= $this->text->e($url) ?>" rel="noopener noreferrer" target="_blank"><?= $this->text->e($url) ?></a></li>
    <?php endif ?>
</ul>

<?php // $body is KanboardHost::markdown() output: safe-mode Markdown with link and image allow-lists. ?>
<article class="markdown agentchat-knowledge__body"><?= $body ?></article>
