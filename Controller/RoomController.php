<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use ChatParts\View\ContextMeter;
use Kanboard\Plugin\AgentChat\Bot\AskerScope;
use Kanboard\Plugin\AgentChat\Bot\ContextBudget;
use Kanboard\Plugin\AgentChat\Bot\Mention;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AgentChat\Helper\KanboardHost;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Model\Busy;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Model\RateLimited;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;

/**
 * The project chat room: the page, the 4-second poll, the send endpoint, the bot's stop and
 * the composer's context ring.
 *
 * The room is always the project's (route param project_id), looked up after the access
 * check; a client never names a room, sender, visibility or recipient. A `room` param that
 * is not the project's room is refused.
 *
 * A message may carry up to four images (`images[]`, multipart); PostsMessages checks and stores
 * them with the message.
 *
 * A sent message that mentions @bot (Mention), from someone who may ask while the project's
 * bot gate is open, also gets the bot's draft and a run: the response carries the draft wired
 * to its stream (SseSink::draftBubble), for the asker only. Without that right or gate the
 * message is just a message. Over a run limit the message is still stored and the response is
 * a Notice (429); so is a question too long for the project's model (413), and no run starts. A retry with the same client_msg_id never starts a second run: it gets the
 * existing answer's bubble, without a stream. Poll and send reap stale runs first.
 */
class RoomController extends BaseController
{
    use PostsMessages;

    private const PAGE_SIZE = 50;

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

        $host = new KanboardHost($this->container);
        $host->registerAssets();
        $this->response->html($this->helper->layout->app('AgentChat:room/page', [
            'project' => $project,
            'title' => $project['name'].' &gt; '.t('Chat'),
            'host' => $host,
            'messages' => array_map([$messages, 'toChatPartsMessage'], $rows),
            'afterId' => $rows === [] ? 0 : (int) end($rows)['id'],
            'canPost' => $canPost,
            // Asked at request time: AiConnector may initialize after this plugin.
            'canAsk' => $policy->canAskBot($userId, (int) $project['id']),
            'botImages' => (new ProjectSettings($this->container, (int) $project['id']))->botImages(),
            // Managers learn why there is no bot; everyone else just does not see it.
            'botUnavailable' => $policy->canManage($userId, (int) $project['id']) && ! AiGate::isReady($this->container),
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
        (new RunModel($this->container))->reap();

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
        $policy = new AccessPolicy($this->container);
        if (! $policy->canPost($userId, $projectId)) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();
        $runs = new RunModel($this->container);
        $runs->reap();

        $body = $this->postedBody();
        if ($body === null) {
            return;
        }
        $room = (new RoomModel($this->container))->forProject($projectId);
        $this->assertRoomParam($room);
        $posted = $this->postMessage((int) $room['id'], $userId, $body);
        if ($posted === null) {
            return;
        }

        $turn = null;
        $limit = null;
        $limitStatus = 429;
        if (Mention::ofBot($body) && $policy->canAskBot($userId, $projectId)) {
            $botImages = (new ProjectSettings($this->container, $projectId))->botImages() ? $posted['images'] : 0;
            if (! (new ContextBudget($this->container))->questionFits($projectId, $userId, $body, $botImages)) {
                [$limit, $limitStatus] = [t('Your message is too long for this model.'), 413];
            } else {
                try {
                    $turn = $runs->ask((int) $room['id'], $userId, $posted['id']);
                } catch (RateLimited|Busy $e) {
                    $limit = $e->getMessage();
                }
            }
        }

        // Without JS the composer is a plain form: the answer is written now, then back to the room.
        $this->respondToSend($posted['id'], $turn, $posted['warning'], $limit, $limitStatus);
    }

    /**
     * Stops the asker's answer: `run_id` names one run of this room, without it every run the
     * asker has going in the room stops. Only the asker may stop a run (403 otherwise); a run
     * that already finished is left alone (200).
     */
    public function stop(): void
    {
        $projectId = $this->request->getIntegerParam('project_id');
        $userId = (int) $this->userSession->getId();
        if (! $this->request->isPost() || ! (new AccessPolicy($this->container))->canView($userId, $projectId)) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $room = (new RoomModel($this->container))->findForProject($projectId);
        $runs = new RunModel($this->container);
        $runId = $this->request->getRawValue('run_id');
        if ($runId !== null && $runId !== '') {
            $run = is_string($runId) && ctype_digit($runId) ? $runs->get((int) $runId) : null;
            if ($run === null || $room === null || (int) $run['room_id'] !== (int) $room['id'] || ! $runs->requestStop((int) $runId, $userId)) {
                throw new AccessForbiddenException();
            }
        } elseif ($room !== null) {
            foreach ($runs->activeIds($userId, (int) $room['id']) as $id) {
                $runs->requestStop($id, $userId);
            }
        }
        $this->response->status(200);
    }

    /**
     * The composer's context ring: a ContextMeter for the next question from the draft's
     * length (`draft_len`, 0–8 000 characters) and image count (`images`, 0–4); anything not a
     * whole number counts as 0. In a task drawer (`task_id`) the task segment counts and the
     * history is the asker's private thread on it; a task the asker cannot read (another
     * project's, a missing one) is a 403. 204 (the ring stays as it is) for anyone who may not
     * ask the bot here, or while the project's AI gate is closed; 405 for anything but GET.
     */
    public function context(): void
    {
        if ($this->request->getMethod() !== 'GET') {
            $this->response->status(405);
            return;
        }
        $projectId = $this->request->getIntegerParam('project_id');
        $userId = (int) $this->userSession->getId();
        $policy = new AccessPolicy($this->container);
        if (! $policy->canView($userId, $projectId)) {
            throw new AccessForbiddenException();
        }
        $taskId = $this->request->getIntegerParam('task_id');
        if ($taskId !== 0 && ! (new AskerScope($this->container, $userId, $projectId))->canReadTask($taskId)) {
            throw new AccessForbiddenException();
        }
        $room = (new RoomModel($this->container))->forProject($projectId);
        $this->assertRoomParam($room);
        if (! $policy->canAskBot($userId, $projectId)) {
            $this->response->status(204);
            return;
        }

        $draftLen = min(MessageModel::MAX_BODY, $this->request->getIntegerParam('draft_len'));
        $images = min(ContextBudget::MAX_IMAGES, $this->request->getIntegerParam('images'));
        $budget = (new ContextBudget($this->container))->breakdown((int) $room['id'], $userId, $taskId === 0 ? null : $taskId, $draftLen, $images);
        $this->response->html((string) new ContextMeter(new KanboardHost($this->container), $budget['segments'], $budget['window']));
    }

    private function back(): string
    {
        return $this->helper->url->to('RoomController', 'show', ['project_id' => $this->request->getIntegerParam('project_id'), 'plugin' => 'AgentChat']);
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
}
