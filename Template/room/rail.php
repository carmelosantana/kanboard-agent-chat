<?php // On narrow screens the rail sits behind a "People" toggle: a checkbox and its label, so it needs no script. ?>
<div class="agentchat-people">
    <input type="checkbox" id="agentchat-people-toggle" class="agentchat-people__toggle" aria-controls="agentchat-people-rail">
    <label for="agentchat-people-toggle" class="agentchat-people__button"><i class="fa fa-users fa-fw" aria-hidden="true"></i><?= t('People') ?></label>
    <div id="agentchat-people-rail" class="agentchat-people__rail"><?= new \ChatParts\View\PresenceList($host, $members) ?></div>
</div>
