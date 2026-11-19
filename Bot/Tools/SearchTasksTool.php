<?php

namespace Kanboard\Plugin\AgentChat\Bot\Tools;

use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Kanboard\Model\ColumnModel;
use Kanboard\Model\TaskModel;
use Kanboard\Model\UserModel;

/** Open and closed tasks of the room's project whose title or description has every query word; in a task drawer, that task first. */
class SearchTasksTool extends ReadOnlyTool
{
    public const MAX_LIMIT = 10;

    public function name(): string
    {
        return 'search_tasks';
    }

    public function description(): string
    {
        return 'Search this project\'s tasks by words in the title or description (every word must match). Returns task ids for get_task.';
    }

    public function parameters(): array
    {
        return [
            new StringParameter('query', 'Words to look for.', true, maxLength: self::MAX_QUERY),
            new EnumParameter('status', 'Task status; default all.', ['open', 'closed', 'all'], false),
            new NumberParameter('limit', 'Most results to return; default '.self::MAX_LIMIT.'.', false, integer: true, minimum: 1, maximum: self::MAX_LIMIT),
        ];
    }

    protected function run(array $input): ToolResult
    {
        $words = self::words($input['query']);
        if ($words === []) {
            return $this->result(['results' => []]);
        }

        $query = $this->scope->container()['db']->table(TaskModel::TABLE)
            ->columns(
                TaskModel::TABLE.'.id',
                TaskModel::TABLE.'.title',
                TaskModel::TABLE.'.is_active',
                TaskModel::TABLE.'.date_modification',
                ColumnModel::TABLE.'.title AS column_title',
                UserModel::TABLE.'.name AS assignee_name',
                UserModel::TABLE.'.username AS assignee_username'
            )
            ->join(ColumnModel::TABLE, 'id', 'column_id')
            ->join(UserModel::TABLE, 'id', 'owner_id')
            ->eq(TaskModel::TABLE.'.project_id', $this->scope->projectId);
        foreach ($words as $word) {
            $query->beginOr()
                ->ilike(TaskModel::TABLE.'.title', '%'.$word.'%')
                ->ilike(TaskModel::TABLE.'.description', '%'.$word.'%')
                ->closeOr();
        }
        $status = $input['status'] ?? 'all';
        if ($status !== 'all') {
            $query->eq(TaskModel::TABLE.'.is_active', $status === 'open' ? TaskModel::STATUS_OPEN : TaskModel::STATUS_CLOSED);
        }
        if ($this->scope->taskId !== null) {
            $query->orderBy('CASE WHEN '.TaskModel::TABLE.'.id = '.$this->scope->taskId.' THEN 0 ELSE 1 END', 'ASC');
        }
        $rows = $query
            ->orderBy(TaskModel::TABLE.'.date_modification', 'DESC')
            ->orderBy(TaskModel::TABLE.'.id', 'DESC')
            ->limit($input['limit'] ?? self::MAX_LIMIT)
            ->findAll();

        return $this->listResult(array_map(function (array $row): array {
            $title = self::text($row['title']);
            return [
                'n' => self::source('task', $row['id'], $title, $this->url('TaskViewController', 'show', ['task_id' => $row['id'], 'project_id' => $this->scope->projectId])),
                'task_id' => (int) $row['id'],
                'title' => $title,
                'status' => (int) $row['is_active'] === TaskModel::STATUS_OPEN ? 'open' : 'closed',
                'column' => self::text($row['column_title']),
                'assignee' => self::text($row['assignee_name'] ?: $row['assignee_username']),
                'updated' => self::date($row['date_modification']),
            ];
        }, $rows));
    }
}
