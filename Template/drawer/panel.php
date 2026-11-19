<?php
// The drawer: the asker's private thread on this task (no polling: only they write to it), then
// the composer and "Share to room" for whoever may ask the bot here now.
$taskId = (int) $task['id'];
$body = (string) new \ChatParts\View\Conversation($host, $messages, '', 0);
if ($canAsk) {
    $body .= new \ChatParts\View\Composer($host, $host->url('drawer_send', ['task_id' => $taskId]), [
        'placeholder' => $host->t('Ask the bot about this task'),
        'allowImages' => $canPost,
        'botSeesImages' => $botImages,
        'stopUrl' => $host->url('stop', ['project_id' => $task['project_id']]),
        'contextUrl' => $host->url('context', ['project_id' => $task['project_id'], 'task_id' => $taskId]),
    ]);
    if ($canPost) {
        $share = $host->url('drawer_share', ['task_id' => $taskId]);
        $hx = '';
        foreach (\ChatParts\View\Hx::attrs(['post' => $share, 'target' => '#cp-conversation', 'swap' => 'beforeend']) as $name => $value) {
            $hx .= ' '.$name.'="'.$host->escape($value).'"';
        }
        $body .= '<form class="agentchat-share" method="post" action="'.$host->escape($share).'"'.$hx.'>'
            .$host->csrfField()
            .'<button type="submit" class="agentchat-share__button">'.$host->escape($host->t('Share to room')).'</button>'
            .'</form>';
    }
} else {
    $body .= new \ChatParts\View\Notice($host, 'info', t('The bot is not available here.'));
}
?>
<?= new \ChatParts\View\Drawer($host, t('Ask the bot'), t('Private to you, about task #%d', $taskId), $body) ?>
