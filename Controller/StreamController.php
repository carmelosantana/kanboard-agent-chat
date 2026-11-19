<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use ChatParts\Stream\SseWriter;
use Kanboard\Controller\BaseController;
use Kanboard\Plugin\AgentChat\Bot\BotRunner;
use Kanboard\Plugin\AgentChat\Bot\DbCancellationToken;
use Kanboard\Plugin\AgentChat\Bot\SseSink;
use Kanboard\Plugin\AgentChat\Helper\KanboardHost;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Model\LimitsSettings;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;

/**
 * GET agentchat/stream?sid=…: one bot answer, streamed to its asker as HTML over SSE (SseSink).
 *
 * The sid is claimed atomically (RunModel::claim): only the asker, only once. Every answer this
 * URL gives is an event stream that ends with `done`, refusals and failures included, because
 * htmx-ext-sse reconnects on anything else (a 204 or an error status), forever: a spent,
 * unknown or foreign sid gets `done` alone (SseWriter::refuse); a closed gate ends the claimed
 * run as an error and answers the same way; an exception is logged, ends a claimed run as an
 * error (stream_failed) and closes the stream.
 * Kanboard's own Response is not used, so nothing buffers the frames.
 *
 * A client that goes away is a stop (stop_requested = STOPPED): the answer so far is stored as
 * stopped. ignore_user_abort keeps PHP running long enough to store it.
 */
class StreamController extends BaseController
{
    public const HEARTBEAT_SECONDS = 15;

    public function stream(): void
    {
        $writer = $this->writer();
        // The stream holds this request for up to the run time limit. PHP keeps the session
        // file locked until the request ends, so without this every other request of this
        // user (the room poll, the stop button) would wait for the whole answer.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        $userId = (int) $this->userSession->getId();
        $runId = null;

        try {
            $runs = new RunModel($this->container);
            $runs->reap();
            $run = $runs->claim($this->request->getStringParam('sid'), $userId);
            if ($run === null) {
                $writer->refuse();
                return;
            }
            $runId = (int) $run['id'];
            $room = (new RoomModel($this->container))->get((int) $run['room_id']);
            if ($room === null || ! AiGate::isOpenFor($this->container, (int) $room['project_id'])) {
                $runs->fail($runId, 'gate_closed');
                $writer->refuse();
                return;
            }

            @set_time_limit((int) ceil((new LimitsSettings($this->container))->maxRunSeconds()) + RunModel::GRACE_SECONDS);
            $writer->open();
            $gone = false;
            $sink = new SseSink($writer, new KanboardHost($this->container), (string) $run['message_id'], $runId, function () use ($writer, $runs, $runId, $userId, &$gone): void {
                $writer->maybeHeartbeat(self::HEARTBEAT_SECONDS);
                if (! $gone && $this->connectionAborted()) {
                    $gone = true;
                    $runs->requestStop($runId, $userId, DbCancellationToken::STOPPED);
                }
            });
            BotRunner::forContainer($this->container)->run($runId, $sink);
            $writer->close();
        } catch (\Throwable $e) {
            $this->logger->error('AgentChat: stream failed: '.get_class($e).': '.$e->getMessage());
            // A claimed run ends now as an error (the generic text), not after the reaper's wait.
            if ($runId !== null) {
                try {
                    (new RunModel($this->container))->fail($runId, 'stream_failed');
                } catch (\Throwable $e) {
                    $this->logger->error('AgentChat: could not end bot run #'.$runId.': '.$e->getMessage());
                }
            }
            $writer->close();
        }
    }

    protected function writer(): SseWriter
    {
        return new SseWriter();
    }

    /** Whether the client has gone; PHP notices only when a write fails, so it is asked after each event. */
    protected function connectionAborted(): bool
    {
        return connection_aborted() === 1;
    }
}
