<?php
namespace Kanboard\Plugin\AgentChat;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Api\AgentChatKnowledgeProcedure;
use Kanboard\Plugin\AgentChat\Api\AgentChatRoomProcedure;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;

class Plugin extends Base
{
    public function initialize(): void
    {
        // chatparts UI kit, vendored as a hash-checked copy (scripts/sync-chatparts.sh).
        require_once __DIR__.'/vendor-chatparts/autoload.php';

        $this->route->addRoute('project/:project_id/chat', 'RoomController', 'show', 'AgentChat');
        $this->route->addRoute('agentchat/:project_id/poll', 'RoomController', 'poll', 'AgentChat');
        $this->route->addRoute('agentchat/:project_id/send', 'RoomController', 'send', 'AgentChat');
        // Core's middleware asks for a project role first; RoomController applies AccessPolicy on top.
        $this->projectAccessMap->add('RoomController', '*', Role::PROJECT_VIEWER);
        // No project_id in this route: KnowledgeController checks AccessPolicy for the document's project.
        $this->route->addRoute('agentchat/knowledge/:id', 'KnowledgeController', 'show', 'AgentChat');

        // JSON-RPC: withObject so core wins any name clash (withCallback/withClassAndMethod would shadow core).
        $this->api->getProcedureHandler()->withObject(new AgentChatKnowledgeProcedure($this->container));
        $this->api->getProcedureHandler()->withObject(new AgentChatRoomProcedure($this->container));

        $this->hook->on('template:project-header:view-switcher', [
            'template' => 'AgentChat:project_header/tab',
            'callable' => fn (array $project) => [
                'agentchat_can_view' => (new AccessPolicy($this->container))->canView((int) $this->userSession->getId(), (int) $project['id']),
            ],
        ]);
        // The kit's CSS, JS and htmx-config meta are registered by RoomController::show(), so
        // they load on the chat page only.
    }

    public function getPluginName(): string        { return 'AgentChat'; }
    public function getPluginDescription(): string { return t('Project chat rooms for people and agents, with an AI project bot.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-agentchat'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
