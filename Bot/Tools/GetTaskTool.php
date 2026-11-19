<?php

namespace Kanboard\Plugin\AgentChat\Bot\Tools;

use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Kanboard\Model\CommentModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskModel;
use Kanboard\Model\UserModel;

/** One task of the room's project with its description, subtasks and last 20 comments the asker may read. */
class GetTaskTool extends ReadOnlyTool
{
    public const MAX_COMMENTS = 20;
    private const SUBTASK_STATUS = [SubtaskModel::STATUS_TODO => 'todo', SubtaskModel::STATUS_INPROGRESS => 'in progress', SubtaskModel::STATUS_DONE => 'done'];

    public function name(): string
    {
        return 'get_task';
    }

    public function description(): string
    {
        return 'Read one task of this project: description, subtasks and its last '.self::MAX_COMMENTS.' comments.';
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

        return $this->result(['task' => [
            'n' => $this->sources->register('task', (string) $taskId, $title, $this->url('TaskViewController', 'show', ['task_id' => $taskId, 'project_id' => $this->scope->projectId])),
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
            ], $subtasks),
            'comments' => $this->lastComments($taskId),
        ]]);
    }

    /** @return list<array{comment_id: int, author: string, created: ?string, text: string}> oldest first */
    private function lastComments(int $taskId): array
    {
        $rows = $this->scope->container()['db']->table(CommentModel::TABLE)
            ->columns(CommentModel::TABLE.'.id', CommentModel::TABLE.'.comment', CommentModel::TABLE.'.date_creation', UserModel::TABLE.'.name', UserModel::TABLE.'.username')
            ->join(UserModel::TABLE, 'id', 'user_id')
            ->eq(CommentModel::TABLE.'.task_id', $taskId)
            ->in(CommentModel::TABLE.'.visibility', $this->scope->commentVisibilities())
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
