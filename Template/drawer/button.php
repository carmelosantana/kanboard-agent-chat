<?php
// "Ask the bot" on the task view: htmx loads the drawer into the empty #cp-drawer (the kit's
// close button hides it, a click loads it afresh); without JS it is a link to the drawer page.
// The drawer's response sends the link back out of band ($oob) marked expanded.
if (empty($agentchat_can_ask)) {
    return;
}
$oob = $oob ?? false;
$url = $this->text->e($this->url->to('DrawerController', 'show', ['task_id' => $task['id'], 'plugin' => 'AgentChat']));
$link = '<a id="agentchat-drawer-open" href="'.$url.'" hx-get="'.$url.'" hx-target="#cp-drawer" hx-swap="outerHTML" aria-controls="cp-drawer" aria-expanded="'.($oob ? 'true' : 'false').'"'.($oob ? ' hx-swap-oob="true"' : '').'>'
    .'<i class="fa fa-comments fa-fw" aria-hidden="true"></i>'.t('Ask the bot').'</a>';
?>
<?php if ($oob): ?>
<?= $link ?>
<?php else: ?>
<?php // The drawer page (no JS) has the drawer itself; elsewhere an empty one waits for htmx. ?>
<li><?= $link ?><?php if ($this->app->getRouterController() !== 'DrawerController'): ?><aside id="cp-drawer" class="cp-drawer" hidden></aside><?php endif ?></li>
<?php endif ?>
