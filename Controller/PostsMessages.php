<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use ChatParts\Model\Part\FilePart;
use ChatParts\Model\Part\TextPart;
use ChatParts\View\Notice;
use InvalidArgumentException;
use Kanboard\Plugin\AgentChat\Bot\BotRunner;
use Kanboard\Plugin\AgentChat\Bot\SseSink;
use Kanboard\Plugin\AgentChat\Helper\KanboardHost;
use Kanboard\Plugin\AgentChat\Model\FileModel;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;

/**
 * Posting a composer's message, shared by the room (RoomController::send) and the task drawer
 * (DrawerController::send): the checks, the images, the message, and the response with the
 * bot's streaming draft. The caller has checked access and CSRF and picked the room.
 */
trait PostsMessages
{
    /**
     * The posted `message`, or null (and a 400 Notice) when there is none or it is over
     * MAX_REQUEST_BYTES: checked before anything else is looked up or created.
     */
    private function postedBody(): ?string
    {
        $body = $this->request->getRawValue('message');
        if (! is_string($body)) {
            $this->notice(t('Write a message first.'));
            return null;
        }
        if (strlen($body) > MessageModel::MAX_REQUEST_BYTES) {
            $this->notice(t('This message is too long to send.'));
            return null;
        }
        return $body;
    }

    /**
     * Checks $body (from postedBody()) and the posted `images[]`, and stores them as the
     * current user's message in $roomId with $opts (visibility, private_to, task_id; never
     * from the request).
     *
     * A message may carry up to four images: all are checked before anything is stored
     * (FileModel::checkAll; any refusal is a 400 Notice and nothing is posted), then the files,
     * then the message with a FilePart per image after its text. Text stays required. A retry
     * with the same client_msg_id stores nothing new and gets the first message; one whose id
     * names a message of another thread (room or drawer) is a 400 Notice.
     *
     * @return ?array{id: int, images: int, warning: ?string} null when a Notice was already sent
     */
    private function postMessage(int $roomId, int $userId, string $body, array $opts = []): ?array
    {
        // Images are checked as a batch before anything is stored; text stays required.
        $files = new FileModel($this->container);
        $uploads = FileModel::uploads($this->request->getFileInfo('images'));
        if (($error = MessageModel::validateBody($body)) !== null) {
            $this->notice($error);
            return null;
        }
        try {
            $files->checkAll($uploads);
        } catch (InvalidArgumentException $e) {
            $this->notice($e->getMessage());
            return null;
        }

        $clientMsgId = $this->request->getRawValue('client_msg_id');
        $messages = new MessageModel($this->container);
        $sender = (new ParticipantResolver($this->container))->forUser($userId);
        $text = new TextPart('t'.bin2hex(random_bytes(8)), $body); // unique: the bubble's DOM id derives from it
        // A retry stores nothing new: create() below returns the first message, images and all.
        $isRetry = $messages->idForClientMsgId($roomId, $sender, $clientMsgId) !== null;
        [$stored, $fileParts] = $isRetry ? [[], []] : $this->storeImages($files, $roomId, $userId, $uploads);
        if ($stored === null) {
            $this->notice(t('The images could not be stored. Nothing was sent.'), 500);
            return null;
        }
        try {
            $id = $messages->create(
                $roomId,
                $sender,
                [$text, ...$fileParts],
                $opts + ($clientMsgId === null || $clientMsgId === '' ? [] : ['client_msg_id' => $clientMsgId]),
            );
        } catch (InvalidArgumentException $e) {
            // Body problems were answered above; anything else (a malformed client_msg_id)
            // gets a fixed message rather than the model's developer text.
            $files->remove($stored);
            $this->notice(t('This message could not be sent.'));
            return null;
        } catch (\Throwable $e) {
            $files->remove($stored);
            throw $e;
        }
        // A client_msg_id is the sender's per room: a retry must land in the same thread (the
        // room, or this drawer), never pick up a message of another one.
        $row = $messages->getById($id);
        if ($row['visibility'] !== ($opts['visibility'] ?? 'room') || (int) $row['task_id'] !== (int) ($opts['task_id'] ?? 0)) {
            $files->remove($stored);
            $this->notice(t('This message could not be sent.'));
            return null;
        }
        $warning = null;
        if ($stored !== []) {
            // Lost a race on client_msg_id: the other request's message (and images) stands.
            $ours = in_array('f'.$stored[0], array_column(json_decode((string) $row['parts'], true) ?: [], 'id'), true);
            if (! $ours) {
                $files->remove($stored);
            } elseif (! $files->linkToMessage($stored, $id)) {
                // The message is out; rather than images nobody can open, it keeps its text alone.
                $this->logger->error("AgentChat: images of message #$id could not be linked to it.");
                $files->remove($stored);
                $messages->update($id, ['parts' => [$text]]);
                $warning = t('The images could not be attached; the message was sent without them.');
            }
        }
        return ['id' => $id, 'images' => count($uploads), 'warning' => $warning];
    }

    /**
     * The response to a send: without JS the answer is written now, then a redirect to
     * back() with any warning or limit as a flash message; with htmx the stored bubble, then
     * the bot's draft wired to its stream (or, for a retry, the existing answer without a
     * stream), a warning, and a limit Notice with its status.
     *
     * @param ?array{run_id: int, sid: ?string, message_id: int} $turn
     */
    private function respondToSend(int $id, ?array $turn, ?string $warning, ?string $limit, int $limitStatus): void
    {
        if (! $this->isHtmx()) {
            if ($turn !== null) {
                BotRunner::forContainer($this->container)->runToCompletion($turn['run_id']);
            }
            // The warning and the limit Notice become the next page's flash message.
            if ($warning !== null || $limit !== null) {
                $this->flash->failure(implode(' ', array_filter([$warning, $limit])));
            }
            $this->response->redirect($this->back());
            return;
        }

        $messages = new MessageModel($this->container);
        $host = new KanboardHost($this->container);
        $html = $this->bubbles($messages, [$messages->getById($id)]);
        if ($turn !== null && $turn['sid'] !== null) {
            $html .= SseSink::draftBubble($host, $messages->toChatPartsMessage($messages->getById($turn['message_id'])), $turn['run_id'], $host->url('stream', ['sid' => $turn['sid']]));
        } elseif ($turn !== null) {
            // A retried send: the answer already exists and streams to the first response only.
            $html .= $this->bubbles($messages, [$messages->getById($turn['message_id'])]);
        }
        if ($warning !== null) {
            $html .= new Notice($host, 'warning', $warning);
        }
        if ($limit !== null) {
            $this->response->html($html.new Notice($host, 'warning', $limit), $limitStatus);
            return;
        }
        $this->response->html($html);
    }

    /**
     * Stores the checked uploads before their message exists (message_id NULL: no one can read
     * them yet), so the message is created with its FileParts in one write and no poll ever
     * shows it without its images. The files are linked right after the insert, in the same
     * request, so a poll that renders the message between the two statements has its image
     * requests arrive after the link; if the link fails, postMessage() drops the images and warns.
     * Returns the file ids and their parts (root-relative URLs to
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
        $stored = [];
        $parts = [];
        try {
            foreach ($uploads as $upload) {
                $fileId = $files->store($roomId, null, $userId, $upload);
                $stored[] = $fileId;
                $parts[] = $files->part($files->get($fileId));
            }
            return [$stored, $parts];
        } catch (\Throwable $e) {
            $this->logger->error('AgentChat: images not stored: '.get_class($e).': '.$e->getMessage());
            $files->remove($stored);
            return [null, []];
        }
    }

    private function bubbles(MessageModel $messages, array $rows): string
    {
        return $this->template->render('AgentChat:room/messages', [
            'host' => new KanboardHost($this->container),
            'messages' => array_map([$messages, 'toChatPartsMessage'], $rows),
        ]);
    }

    /**
     * A refusal: an error Notice with $status for htmx (the kit swaps it in); without JS a flash
     * message and a redirect to back(), never a bare fragment as the page.
     */
    private function notice(string $text, int $status = 400): void
    {
        if (! $this->isHtmx()) {
            $this->flash->failure($text);
            $this->response->redirect($this->back());
            return;
        }
        $this->response->html((string) new Notice(new KanboardHost($this->container), 'error', $text), $status);
    }

    private function isHtmx(): bool
    {
        return $this->request->getHeader('HX-Request') !== '';
    }

    /** Where a form posted without JS goes back to: the room, or the drawer's page. */
    abstract private function back(): string;
}
