<?php
namespace Kanboard\Plugin\AgentChat;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
        // chatparts UI kit, vendored as a hash-checked copy (scripts/sync-chatparts.sh).
        require_once __DIR__.'/vendor-chatparts/autoload.php';
    }

    public function getPluginName(): string        { return 'AgentChat'; }
    public function getPluginDescription(): string { return t('Project chat rooms for people and agents, with an AI project bot.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-agentchat'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
