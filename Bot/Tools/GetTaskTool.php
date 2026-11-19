<?php

namespace Kanboard\Plugin\AgentChat\Bot\Tools;

use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Kanboard\Model\CommentModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskModel;
use Kanboard\Model\UserModel;

/**
 * One task of the room's project with its description, its first 50 subtasks and the last 20
 * comments the asker may read, with both totals; `truncated` is set when either list is cut.
 */
class GetTaskTool extends ReadOnlyTool
{
    public const MAX_COMMENTS = 20;
    public const MAX_SUBTASKS = 50;
    private const SUBTASK_STATUS = [SubtaskModel::STATUS_TODO => 'todo', SubtaskModel::STATUS_INPROGRESS => 'in progress', SubtaskModel::STATUS_DONE => 'done'];

    public function name(): string
    {
        return 'get_task';
    }

    public function description(): string
    {
        return 'Read one task of this project: description, its first '.self::MAX_SUBTASKS.' subtasks and its last '.self::MAX_COMMENTS.' comments.';
    }

    public function parameters(): array
    {
        return [new NumberParameter('task_id', 'The task id.', true, integer: true, minimum: 1)];
    }

    protected function run(array $input): ToolResult
    {
        $taskId = (int) $input['task_id'];
        $task = $this->scope->canReadTask($taskId) ? $this->scope->container()['taskFinderModel']->getDetails($taskId) : null;
        if (empty($task)) {
            return ToolResult::error(self::NOT_FOUND);
        }

        $title = self::text($task['title']);
        $subtasks = $this->scope->container()['subtaskModel']->getAll($taskId);
        $subtasksTotal = count($subtasks);
        $commentsTotal = $this->commentQuery($taskId)->count();
        $item = [
            'n' => self::source('task', $taskId, $title, $this->url('TaskViewController', 'show', ['task_id' => $taskId, 'project_id' => $this->scope->projectId])),
            'task_id' => $taskId,
            'title' => $title,
            'status' => (int) $task['is_active'] === TaskModel::STATUS_OPEN ? 'open' : 'closed',
            'column' => self::text($task['column_title']),
            'assignee' => self::text($task['assignee_name'] ?: $task['assignee_username']),
            'due' => self::date($task['date_due']),
            'description' => self::text($task['description']),
            'subtasks' => array_map(static fn (array $s): array => [
                'title' => self::text($s['title']),
                'status' => self::SUBTASK_STATUS[(int) $s['status']] ?? 'todo',
            ], array_slice($subtasks, 0, self::MAX_SUBTASKS)),
            'subtasks_total' => $subtasksTotal,
            'comments' => $this->lastComments($taskId),
            'comments_total' => $commentsTotal,
        ];

        // Over the size cap, drop the oldest comments first, then subtasks from the end.
        $payload = static fn (array $task): array => ['task' => $task]
            + (count($task['subtasks']) < $subtasksTotal || count($task['comments']) < min(self::MAX_COMMENTS, $commentsTotal) ? ['truncated' => true] : []);
        while (! $this->fits($payload(self::unnumbered($item)))) {
            if ($item['comments'] !== []) {
                array_shift($item['comments']);
            } elseif ($item['subtasks'] !== []) {
                array_pop($item['subtasks']);
            } else {
                break;
            }
        }

        return $this->result($payload($this->numbered($item)));
    }

    private function commentQuery(int $taskId): \PicoDb\Table
    {
        return $this->scope->container()['db']->table(CommentModel::TABLE)
            ->eq(CommentModel::TABLE.'.task_id', $taskId)
            ->in(CommentModel::TABLE.'.visibility', $this->scope->commentVisibilities());
    }

    /** @return list<array{comment_id: int, author: string, created: ?string, text: string}> oldest first */
    private function lastComments(int $taskId): array
    {
        $rows = $this->commentQuery($taskId)
            ->columns(CommentModel::TABLE.'.id', CommentModel::TABLE.'.comment', CommentModel::TABLE.'.date_creation', UserModel::TABLE.'.name', UserModel::TABLE.'.username')
            ->join(UserModel::TABLE, 'id', 'user_id')
            ->orderBy(CommentModel::TABLE.'.date_creation', 'DESC')
            ->orderBy(CommentModel::TABLE.'.id', 'DESC')
            ->limit(self::MAX_COMMENTS)
            ->findAll();

        return array_reverse(array_map(static fn (array $c): array => [
            'comment_id' => (int) $c['id'],
            'author' => self::text($c['name'] ?: $c['username']),
            'created' => self::date($c['date_creation']),
            'text' => self::text($c['comment']),
        ], $rows));
    }
}
