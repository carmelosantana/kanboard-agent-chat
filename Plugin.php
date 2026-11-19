<?php
namespace Kanboard\Plugin\AgentChat;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Api\AgentChatKnowledgeProcedure;
use Kanboard\Plugin\AgentChat\Api\AgentChatRoomProcedure;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\ProjectModelWithChatFiles;

class Plugin extends Base
{
    public function initialize(): void
    {
        // chatparts UI kit, vendored as a hash-checked copy (scripts/sync-chatparts.sh).
        require_once __DIR__.'/vendor-chatparts/autoload.php';

        $this->route->addRoute('project/:project_id/chat', 'RoomController', 'show', 'AgentChat');
        $this->route->addRoute('agentchat/:project_id/poll', 'RoomController', 'poll', 'AgentChat');
        $this->route->addRoute('agentchat/:project_id/send', 'RoomController', 'send', 'AgentChat');
        $this->route->addRoute('agentchat/:project_id/stop', 'RoomController', 'stop', 'AgentChat');
        $this->route->addRoute('agentchat/:project_id/context', 'RoomController', 'context', 'AgentChat');
        // No project_id: the single-use sid names the run, and only its asker can claim it.
        $this->route->addRoute('agentchat/stream', 'StreamController', 'stream', 'AgentChat');
        // Core's middleware asks for a project role first; RoomController applies AccessPolicy on top.
        $this->projectAccessMap->add('RoomController', '*', Role::PROJECT_VIEWER);
        // No project_id in this route: KnowledgeController checks AccessPolicy for the document's project.
        $this->route->addRoute('agentchat/knowledge/:id', 'KnowledgeController', 'show', 'AgentChat');
        // Chat images: FileController checks the file's room, project and private owner.
        $this->route->addRoute('agentchat/file/:id', 'FileController', 'show', 'AgentChat');
        $this->route->addRoute('agentchat/file/:id/thumb', 'FileController', 'thumb', 'AgentChat');
        $this->removeChatFilesWithProjects();

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

    /**
     * Kanboard has no project-removal event: swap in a ProjectModel that also removes the
     * project's chat images, unless another plugin already replaced core's (then the blobs of a
     * removed project stay in storage, and the log says so).
     */
    private function removeChatFilesWithProjects(): void
    {
        if (get_class($this->container['projectModel']) !== \Kanboard\Model\ProjectModel::class) {
            $this->logger->warning('AgentChat: projectModel is replaced by another plugin; chat images are not removed with their project.');
            return;
        }
        unset($this->container['projectModel']);
        $this->container['projectModel'] = static fn ($c) => new ProjectModelWithChatFiles($c);
    }

    public function getPluginName(): string        { return 'AgentChat'; }
    public function getPluginDescription(): string { return t('Project chat rooms for people and agents, with an AI project bot.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-agentchat'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
