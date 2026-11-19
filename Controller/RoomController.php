<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use ChatParts\Model\Part\FilePart;
use ChatParts\Model\Part\TextPart;
use ChatParts\View\ContextMeter;
use ChatParts\View\Notice;
use Kanboard\Plugin\AgentChat\Bot\BotRunner;
use Kanboard\Plugin\AgentChat\Bot\ContextBudget;
use Kanboard\Plugin\AgentChat\Bot\Mention;
use Kanboard\Plugin\AgentChat\Bot\SseSink;
use InvalidArgumentException;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AgentChat\Helper\KanboardHost;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Model\Busy;
use Kanboard\Plugin\AgentChat\Model\FileModel;
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
 * A message may carry up to four images (`images[]`, multipart): all are checked before anything
 * is stored (FileModel::checkAll; any refusal is a 400 Notice and nothing is posted), then the
 * files, then the message with a FilePart per image after its text. Text stays required.
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
            'canAsk' => $canPost && $policy->canAsk($userId, (int) $project['id']) && AiGate::isOpenFor($this->container, (int) $project['id']),
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

        $body = $this->request->getRawValue('message');
        if (! is_string($body)) {
            $this->notice(t('Write a message first.'));
            return;
        }
        if (strlen($body) > MessageModel::MAX_REQUEST_BYTES) {
            $this->notice(t('This message is too long to send.'));
            return;
        }

        $room = (new RoomModel($this->container))->forProject($projectId);
        $this->assertRoomParam($room);
        // Images are checked as a batch before anything is stored; text stays required.
        $files = new FileModel($this->container);
        $uploads = FileModel::uploads($this->request->getFileInfo('images'));
        if (($error = MessageModel::validateBody($body)) !== null) {
            $this->notice($error);
            return;
        }
        try {
            $files->checkAll($uploads);
        } catch (InvalidArgumentException $e) {
            $this->notice($e->getMessage());
            return;
        }

        $clientMsgId = $this->request->getRawValue('client_msg_id');
        $messages = new MessageModel($this->container);
        $sender = (new ParticipantResolver($this->container))->forUser($userId);
        $text = new TextPart('t'.bin2hex(random_bytes(8)), $body); // unique: the bubble's DOM id derives from it
        // A retry stores nothing new: create() below returns the first message, images and all.
        $isRetry = $messages->idForClientMsgId((int) $room['id'], $sender, $clientMsgId) !== null;
        [$stored, $fileParts] = $isRetry ? [[], []] : $this->storeImages($files, (int) $room['id'], $userId, $uploads);
        if ($stored === null) {
            $this->response->html((string) new Notice(new KanboardHost($this->container), 'error', t('The images could not be stored. Nothing was sent.')), 500);
            return;
        }
        try {
            $id = $messages->create(
                (int) $room['id'],
                $sender,
                [$text, ...$fileParts],
                $clientMsgId === null || $clientMsgId === '' ? [] : ['client_msg_id' => $clientMsgId],
            );
        } catch (InvalidArgumentException $e) {
            // Body problems were answered above; anything else (a malformed client_msg_id)
            // gets a fixed message rather than the model's developer text.
            $files->remove($stored);
            $this->notice(t('This message could not be sent.'));
            return;
        } catch (\Throwable $e) {
            $files->remove($stored);
            throw $e;
        }
        if ($stored !== []) {
            // Lost a race on client_msg_id: the other request's message (and images) stands.
            $ours = in_array('f'.$stored[0], array_column(json_decode((string) $messages->getById($id)['parts'], true) ?: [], 'id'), true);
            $ours ? $files->linkToMessage($stored, $id) : $files->remove($stored);
        }

        $turn = null;
        $limit = null;
        $limitStatus = 429;
        if (Mention::ofBot($body) && $policy->canAsk($userId, $projectId) && AiGate::isOpenFor($this->container, $projectId)) {
            $botImages = (new ProjectSettings($this->container, $projectId))->botImages() ? count($uploads) : 0;
            if (! (new ContextBudget($this->container))->questionFits($projectId, $userId, $body, $botImages)) {
                [$limit, $limitStatus] = [t('Your message is too long for this model.'), 413];
            } else {
                try {
                    $turn = $runs->ask((int) $room['id'], $userId, $id);
                } catch (RateLimited|Busy $e) {
                    $limit = $e->getMessage();
                }
            }
        }

        // Without JS the composer is a plain form: the answer is written now, then back to the room.
        if ($this->request->getHeader('HX-Request') === '') {
            if ($turn !== null) {
                BotRunner::forContainer($this->container)->runToCompletion($turn['run_id']);
            }
            $this->response->redirect($this->helper->url->to('RoomController', 'show', ['project_id' => $projectId, 'plugin' => 'AgentChat']));
            return;
        }

        $html = $this->bubbles($messages, [$messages->getById($id)]);
        if ($turn !== null && $turn['sid'] !== null) {
            $host = new KanboardHost($this->container);
            $html .= SseSink::draftBubble($host, $messages->toChatPartsMessage($messages->getById($turn['message_id'])), $turn['run_id'], $host->url('stream', ['sid' => $turn['sid']]));
        } elseif ($turn !== null) {
            // A retried send: the answer already exists and streams to the first response only.
            $html .= $this->bubbles($messages, [$messages->getById($turn['message_id'])]);
        }
        if ($limit !== null) {
            $this->response->html($html.new Notice(new KanboardHost($this->container), 'warning', $limit), $limitStatus);
            return;
        }
        $this->response->html($html);
    }

    /**
     * Stores the checked uploads before their message exists (message_id NULL: no one can read
     * them yet), so the message is created with its FileParts in one write and no poll ever
     * shows it without its images. Returns the file ids and their parts (root-relative URLs to
     * FileController); on a failed write, removes what was written and returns [null, []].
     * Compensation rather than a DB transaction: the blobs live outside the database, and a
     * failed insert inside a Postgres transaction would break create()'s client_msg_id race
     * recovery.
     *
     * @param list<array> $uploads
     * @return array{0: ?list<int>, 1: list<FilePart>}
     */
    private function storeImages(FileModel $files, int $roomId, int $userId, array $uploads): array
    {
        $host = new KanboardHost($this->container);
        $stored = [];
        $parts = [];
        try {
            foreach ($uploads as $upload) {
                $fileId = $files->store($roomId, null, $userId, $upload);
                $stored[] = $fileId;
                $file = $files->get($fileId);
                $parts[] = new FilePart('f'.$fileId, (string) $file['name'], (string) $file['mime'], $host->url('file', ['id' => $fileId]), $file['thumb_key'] === null ? null : $host->url('thumb', ['id' => $fileId]));
            }
            return [$stored, $parts];
        } catch (\Throwable $e) {
            $this->logger->error('AgentChat: images not stored: '.get_class($e).': '.$e->getMessage());
            $files->remove($stored);
            return [null, []];
        }
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
     * whole number counts as 0. 204 (the ring stays as it is) for anyone who may not ask the
     * bot here, or while the project's AI gate is closed; 405 for anything but GET.
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
        $room = (new RoomModel($this->container))->forProject($projectId);
        $this->assertRoomParam($room);
        if (! $policy->canPost($userId, $projectId) || ! $policy->canAsk($userId, $projectId) || ! AiGate::isOpenFor($this->container, $projectId)) {
            $this->response->status(204);
            return;
        }

        $draftLen = min(MessageModel::MAX_BODY, $this->request->getIntegerParam('draft_len'));
        $images = min(ContextBudget::MAX_IMAGES, $this->request->getIntegerParam('images'));
        $budget = (new ContextBudget($this->container))->breakdown((int) $room['id'], $userId, null, $draftLen, $images);
        $this->response->html((string) new ContextMeter(new KanboardHost($this->container), $budget['segments'], $budget['window']));
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
