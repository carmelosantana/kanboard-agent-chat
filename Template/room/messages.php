<?php foreach ($messages as $message): ?>
<?= new \ChatParts\View\MessageBubble($host, $message) ?>
<?php endforeach ?>
