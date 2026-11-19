<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use ChatParts\Model\Part\TextPart;
use ChatParts\View\Notice;
use InvalidArgumentException;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AgentChat\Helper\KanboardHost;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\RoomModel;

/**
 * The project chat room: the page, the 4-second poll and the send endpoint.
 *
 * The room is always the project's (route param project_id), looked up after the access
 * check; a client never names a room, sender, visibility or recipient. A `room` param that
 * is not the project's room is refused.
 */
class RoomController extends BaseController
{
    /** Bytes; anything larger is refused before it reaches the model. */
    public const MAX_REQUEST_BODY = 65536;

    private const PAGE_SIZE = 50;

    private const ASSETS = 'plugins/AgentChat/vendor-chatparts/assets/';

    public function show(): void
    {
        $project = $this->getProject();
        $userId = (int) $this->userSession->getId();
        $policy = new AccessPolicy($this->container);
        if (! $policy->canView($userId, (int) $project['id'])) {
            throw new AccessForbiddenException();
        }

        $room = (new RoomModel($this->container))->forProject((int) $project['id']);
        $messages = new MessageModel($this->container);
        $rows = $messages->latest((int) $room['id'], self::PAGE_SIZE);
        $canPost = $policy->canPost($userId, (int) $project['id']);

        $this->registerAssets();
        $this->response->html($this->helper->layout->app('AgentChat:room/page', [
            'project' => $project,
            'title' => $project['name'].' &gt; '.t('Chat'),
            'host' => new KanboardHost($this->container),
            'messages' => array_map([$messages, 'toChatPartsMessage'], $rows),
            'afterId' => $rows === [] ? 0 : (int) end($rows)['id'],
            'canPost' => $canPost,
            // Asked at request time: AiConnector may initialize after this plugin.
            'canAsk' => $canPost && $policy->canAsk($userId, (int) $project['id']) && AiGate::isReady($this->container),
            'members' => $this->members((int) $project['id']),
        ]));
    }

    public function poll(): void
    {
        $projectId = $this->request->getIntegerParam('project_id');
        $userId = (int) $this->userSession->getId();
        if (! (new AccessPolicy($this->container))->canView($userId, $projectId)) {
            throw new AccessForbiddenException();
        }
        // Read-only: a room nobody has opened yet has no messages.
        $room = (new RoomModel($this->container))->findForProject($projectId);
        $this->assertRoomParam($room);
        if ($room === null) {
            $this->response->status(204);
            return;
        }

        $messages = new MessageModel($this->container);
        // Bot drafts (submitted, streaming) are included: other viewers see the bot answering, and
        // the kit's cursor refetches a non-final bubble until it is final. Polled bubbles never
        // carry sse-connect; only the asker's send response streams.
        $rows = $messages->listAfter((int) $room['id'], $userId, max(0, $this->request->getIntegerParam('after')), self::PAGE_SIZE);
        if ($rows === []) {
            $this->response->status(204);
            return;
        }
        $this->response->html($this->bubbles($messages, $rows));
    }

    public function send(): void
    {
        $projectId = $this->request->getIntegerParam('project_id');
        $userId = (int) $this->userSession->getId();
        if (! (new AccessPolicy($this->container))->canPost($userId, $projectId)) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $body = $this->request->getRawValue('message');
        if (! is_string($body)) {
            $this->notice(t('Write a message first.'));
            return;
        }
        if (strlen($body) > self::MAX_REQUEST_BODY) {
            $this->notice(t('This message is too long to send.'));
            return;
        }

        $room = (new RoomModel($this->container))->forProject($projectId);
        $this->assertRoomParam($room);
        $clientMsgId = $this->request->getRawValue('client_msg_id');
        $messages = new MessageModel($this->container);
        try {
            $id = $messages->create(
                (int) $room['id'],
                (new ParticipantResolver($this->container))->forUser($userId),
                [new TextPart('t'.bin2hex(random_bytes(8)), $body)], // unique: the bubble's DOM id derives from it
                $clientMsgId === null || $clientMsgId === '' ? [] : ['client_msg_id' => $clientMsgId],
            );
        } catch (InvalidArgumentException $e) {
            // Body problems already have a translated message; anything else (a malformed
            // client_msg_id) gets a fixed one rather than the model's developer text.
            $this->notice(MessageModel::validateBody($body) ?? t('This message could not be sent.'));
            return;
        }

        // Without JS the composer is a plain form: go back to the room.
        if ($this->request->getHeader('HX-Request') === '') {
            $this->response->redirect($this->helper->url->to('RoomController', 'show', ['project_id' => $projectId, 'plugin' => 'AgentChat']));
            return;
        }
        $this->response->html($this->bubbles($messages, [$messages->getById($id)]));
    }

    /**
     * A client never picks the room, but one that sends a `room` param (POST or query) must
     * name the project's room; any other value, an array included, is refused.
     */
    private function assertRoomParam(?array $room): void
    {
        $param = $this->request->getRawValue('room') ?? $this->request->getStringParam('room');
        if ($param === '' || $param === null) {
            return;
        }
        if (! is_string($param) || $room === null || $param !== (string) $room['id']) {
            throw new AccessForbiddenException();
        }
    }

    private function bubbles(MessageModel $messages, array $rows): string
    {
        return $this->template->render('AgentChat:room/messages', [
            'host' => new KanboardHost($this->container),
            'messages' => array_map([$messages, 'toChatPartsMessage'], $rows),
        ]);
    }

    private function notice(string $text): void
    {
        $this->response->html((string) new Notice(new KanboardHost($this->container), 'error', $text), 400);
    }

    /** @return array{people: list<array{id: string, name: string}>, agents: list<array{id: string, name: string}>} */
    private function members(int $projectId): array
    {
        $resolver = new ParticipantResolver($this->container);
        $groups = ['people' => [], 'agents' => []];
        foreach (array_keys($this->projectUserRoleModel->getAllUsers($projectId)) as $userId) {
            $p = $resolver->forUser((int) $userId);
            $groups[$p->kind === 'agent' ? 'agents' : 'people'][] = ['id' => (string) $p->id, 'name' => $p->name];
        }
        return $groups;
    }

    /**
     * The kit's stylesheet and the page layout, the kit's scripts (htmx, then its SSE extension, which needs the htmx
     * global, then chatparts.js) and the htmx-config meta, registered here so they load on
     * the chat page only.
     */
    private function registerAssets(): void
    {
        $this->hook->on('template:layout:css', ['template' => self::ASSETS.'chatparts.css']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/AgentChat/Assets/agentchat.css']);
        foreach (['htmx.min.js', 'htmx-ext-sse.js', 'chatparts.js'] as $file) {
            $this->hook->on('template:layout:js', ['template' => self::ASSETS.$file]);
        }
        $this->hook->on('template:layout:head', ['template' => 'AgentChat:layout/htmx-meta']);
    }
}
