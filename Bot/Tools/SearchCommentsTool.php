<?php

namespace Kanboard\Plugin\AgentChat\Bot\Tools;

use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Kanboard\Model\CommentModel;
use Kanboard\Model\TaskModel;
use Kanboard\Model\UserModel;

/** Comments on the room project's tasks that have every query word, newest first, within the asker's comment visibility. */
class SearchCommentsTool extends ReadOnlyTool
{
    public const MAX_LIMIT = 10;

    public function name(): string
    {
        return 'search_comments';
    }

    public function description(): string
    {
        return 'Search comments on this project\'s tasks by words (every word must match), newest first.';
    }

    public function parameters(): array
    {
        return [
            new StringParameter('query', 'Words to look for.', true, maxLength: self::MAX_QUERY),
            new NumberParameter('limit', 'Most results to return; default '.self::MAX_LIMIT.'.', false, integer: true, minimum: 1, maximum: self::MAX_LIMIT),
        ];
    }

    protected function run(array $input): ToolResult
    {
        $words = self::words($input['query']);
        if ($words === []) {
            return $this->result(['results' => []]);
        }

        $query = $this->scope->container()['db']->table(CommentModel::TABLE)
            ->columns(
                CommentModel::TABLE.'.id',
                CommentModel::TABLE.'.task_id',
                CommentModel::TABLE.'.comment',
                CommentModel::TABLE.'.date_creation',
                TaskModel::TABLE.'.title AS task_title',
                UserModel::TABLE.'.name AS author_name',
                UserModel::TABLE.'.username AS author_username'
            )
            ->join(TaskModel::TABLE, 'id', 'task_id')
            ->join(UserModel::TABLE, 'id', 'user_id', CommentModel::TABLE)
            ->eq(TaskModel::TABLE.'.project_id', $this->scope->projectId)
            ->in(CommentModel::TABLE.'.visibility', $this->scope->commentVisibilities());
        foreach ($words as $word) {
            $query->ilike(CommentModel::TABLE.'.comment', '%'.$word.'%');
        }
        $rows = $query
            ->orderBy(CommentModel::TABLE.'.date_creation', 'DESC')
            ->orderBy(CommentModel::TABLE.'.id', 'DESC')
            ->limit($input['limit'] ?? self::MAX_LIMIT)
            ->findAll();

        return $this->listResult(array_map(function (array $row): array {
            $taskTitle = self::text($row['task_title']);
            $url = $this->url('TaskViewController', 'show', ['task_id' => $row['task_id'], 'project_id' => $this->scope->projectId], 'comment-'.$row['id']);
            return [
                'n' => self::source('comment', $row['id'], self::text('Comment on '.$row['task_title']), $url),
                'comment_id' => (int) $row['id'],
                'task_id' => (int) $row['task_id'],
                'task_title' => $taskTitle,
                'author' => self::text($row['author_name'] ?: $row['author_username']),
                'created' => self::date($row['date_creation']),
                'text' => self::text($row['comment']),
            ];
        }, $rows));
    }
}
