<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use ChatParts\View\Notice;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AgentChat\Bot\AskerScope;
use Kanboard\Plugin\AgentChat\Bot\ContextBudget;
use Kanboard\Plugin\AgentChat\Helper\KanboardHost;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\Busy;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\NothingToShare;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Model\RateLimited;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;
use Kanboard\Plugin\AgentChat\Model\ShareModel;

/**
 * The task page's "Ask the bot" drawer (`task_id`): a running private thread per user and
 * task, stored in the project's room as messages with visibility private, private_to the
 * asker and the task id, all three set here and never read from the request.
 *
 *  - show:  the thread in the kit's Drawer (htmx), or on the task page without JS
 *  - send:  stores the question and always starts a run (no @bot needed) for someone who may
 *           ask the bot here now (AccessPolicy::canAskBot; 403 otherwise); its history is the
 *           private thread only (BotRunner). Limits as in the room: 429, or 413 for a
 *           question too long for the model (the task counts), and no run starts
 *  - share: POST, copies the latest question and answer into the room (ShareModel); 409 when
 *           there is no finished answer, 422 when a copy fails, and then nothing is posted
 *
 * Every action first checks that the user can view the task's project and that the task is
 * that project's (AskerScope::canReadTask); otherwise, or for a missing task, 403.
 */
class DrawerController extends BaseController
{
    use PostsMessages;

    private const PAGE_SIZE = 50;

    public function show(): void
    {
        [$task, $projectId, $userId] = $this->readableTask();
        $room = (new RoomModel($this->container))->forProject($projectId);
        $messages = new MessageModel($this->container);
        $host = new KanboardHost($this->container);
        $params = [
            'host' => $host,
            'task' => $task,
            'messages' => array_map([$messages, 'toChatPartsMessage'], $messages->privateThread((int) $room['id'], $userId, (int) $task['id'], self::PAGE_SIZE)),
            'canAsk' => (new AccessPolicy($this->container))->canAskBot($userId, $projectId),
        ];

        if ($this->request->getHeader('HX-Request') !== '') {
            $this->response->html($this->template->render('AgentChat:drawer/panel', $params)
                .$this->template->render('AgentChat:drawer/button', ['task' => $task, 'agentchat_can_ask' => true, 'oob' => true]));
            return;
        }
        // Without JS: the task page with the drawer open over it.
        $host->registerAssets();
        $this->response->html($this->helper->layout->task('AgentChat:drawer/panel', $params + [
            'project' => $this->projectModel->getById($projectId),
        ]));
    }

    public function send(): void
    {
        [$task, $projectId, $userId] = $this->readableTask();
        if (! (new AccessPolicy($this->container))->canAskBot($userId, $projectId)) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();
        $runs = new RunModel($this->container);
        $runs->reap();

        $taskId = (int) $task['id'];
        $private = ['visibility' => 'private', 'private_to' => $userId, 'task_id' => $taskId];
        $body = $this->postedBody();
        if ($body === null) {
            return;
        }
        $room = (new RoomModel($this->container))->forProject($projectId);
        $posted = $this->postMessage((int) $room['id'], $userId, $body, $private);
        if ($posted === null) {
            return;
        }

        $turn = null;
        $limit = null;
        $limitStatus = 429;
        $botImages = (new ProjectSettings($this->container, $projectId))->botImages() ? $posted['images'] : 0;
        if (! (new ContextBudget($this->container))->questionFits($projectId, $userId, $body, $botImages, $taskId)) {
            [$limit, $limitStatus] = [t('Your message is too long for this model.'), 413];
        } else {
            try {
                $turn = $runs->ask((int) $room['id'], $userId, $posted['id'], $private);
            } catch (RateLimited|Busy $e) {
                $limit = $e->getMessage();
            }
        }

        $this->respondToSend($posted['id'], $turn, $posted['warning'], $limit, $limitStatus, $this->drawerUrl($taskId));
    }

    public function share(): void
    {
        [$task, $projectId, $userId] = $this->readableTask();
        if (! $this->request->isPost() || ! (new AccessPolicy($this->container))->canPost($userId, $projectId)) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $room = (new RoomModel($this->container))->forProject($projectId);
        try {
            (new ShareModel($this->container))->share((int) $room['id'], $userId, (int) $task['id']);
        } catch (NothingToShare $e) {
            $this->notice($e->getMessage(), 409);
            return;
        } catch (\Throwable $e) {
            $this->logger->error('AgentChat: share failed: '.get_class($e).': '.$e->getMessage());
            $this->notice(t('The answer could not be shared. Nothing was posted.'), 422);
            return;
        }

        if ($this->request->getHeader('HX-Request') === '') {
            $this->response->redirect($this->drawerUrl((int) $task['id']));
            return;
        }
        $this->response->html((string) new Notice(new KanboardHost($this->container), 'info', t('The question and answer are now in the project chat.')));
    }

    /**
     * The request's task with its project and the current user, once that user can view the
     * project and the task is the project's; a missing task is refused the same way.
     *
     * @return array{0: array, 1: int, 2: int}
     */
    private function readableTask(): array
    {
        $taskId = $this->request->getIntegerParam('task_id');
        $userId = (int) $this->userSession->getId();
        $projectId = $taskId > 0 ? (int) $this->taskFinderModel->getProjectId($taskId) : 0;
        if ($projectId === 0 || ! (new AskerScope($this->container, $userId, $projectId))->canReadTask($taskId)) {
            throw new AccessForbiddenException();
        }
        return [$this->taskFinderModel->getDetails($taskId), $projectId, $userId];
    }

    private function drawerUrl(int $taskId): string
    {
        return (new KanboardHost($this->container))->url('drawer', ['task_id' => $taskId]);
    }
}
