<?php
require_once 'tests/units/Base.php';

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use KanboardTests\units\Base;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Bot\AskerScope;
use Kanboard\Plugin\AgentChat\Bot\SourceRegistry;
use Kanboard\Plugin\AgentChat\Bot\Tools\GetTaskTool;
use Kanboard\Plugin\AgentChat\Bot\Tools\SearchCommentsTool;
use Kanboard\Plugin\AgentChat\Bot\Tools\SearchKnowledgeTool;
use Kanboard\Plugin\AgentChat\Bot\Tools\SearchTasksTool;
use Kanboard\Plugin\AgentChat\Model\KnowledgeModel;

class ToolsTest extends Base
{
    private int $room;
    private int $other;
    private int $asker;
    private SourceRegistry $sources;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../vendor-chatparts/autoload.php';
        // php-agents ships inside AiConnector, a sibling checkout (as in the plugins/ folder).
        require_once dirname(__DIR__, 3).'/AiConnector/vendor/autoload.php';
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        // A web request at the site root, so the url helper builds root-relative links.
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET', 'PHP_SELF' => '/index.php'], [], [], [], []);

        $this->room = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->other = (int) $this->container['projectModel']->create(['name' => 'Secret']);
        $this->asker = $this->user('ana');
        $this->container['projectUserRoleModel']->addUser($this->room, $this->asker, Role::PROJECT_MEMBER);
        $this->sources = new SourceRegistry();
    }

    private function user(string $name, string $role = Role::APP_USER): int
    {
        return (int) $this->container['userModel']->create(['username' => $name, 'name' => ucfirst($name), 'role' => $role]);
    }

    private function task(int $projectId, string $title, string $description = ''): int
    {
        return (int) $this->container['taskCreationModel']->create(['project_id' => $projectId, 'title' => $title, 'description' => $description]);
    }

    private function comment(int $taskId, string $text, string $visibility = Role::APP_USER): int
    {
        return (int) $this->container['commentModel']->create(['task_id' => $taskId, 'user_id' => $this->asker, 'comment' => $text, 'visibility' => $visibility]);
    }

    private function scope(?int $userId = null, ?int $taskId = null): AskerScope
    {
        return new AskerScope($this->container, $userId ?? $this->asker, $this->room, $taskId);
    }

    /** @param class-string<ToolInterface> $class */
    private function tool(string $class, ?AskerScope $scope = null): ToolInterface
    {
        return new $class($scope ?? $this->scope(), $this->sources);
    }

    private function json(ToolResult $result): array
    {
        $this->assertSame(ToolResultStatus::Success, $result->status, $result->content);
        $data = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($data['untrusted'], 'every result is marked untrusted');
        return $data;
    }

    private function assertNotFound(ToolResult $result): void
    {
        $this->assertSame(ToolResultStatus::Error, $result->status);
        $this->assertSame('not found', $result->content);
    }

    public function testGetTaskOutsideProjectIsNotFound(): void
    {
        $hidden = $this->task($this->other, 'Acquisition plan', 'Confidential');
        $getTask = $this->tool(GetTaskTool::class);

        $this->assertNotFound($getTask->execute(['task_id' => $hidden]));
        $this->assertNotFound($getTask->execute(['task_id' => 99999]), 'a missing id reads the same');

        // Being a member of the other project does not widen the room's scope.
        $this->container['projectUserRoleModel']->addUser($this->other, $this->asker, Role::PROJECT_MANAGER);
        $this->assertNotFound($getTask->execute(['task_id' => $hidden]));
        $this->assertFalse($this->scope()->canReadTask($hidden));

        $this->assertSame([], $this->sources->all());
    }

    public function testGetTaskReturnsDescriptionSubtasksAndLastTwentyComments(): void
    {
        $id = $this->task($this->room, 'Fix login', 'Users cannot log in.');
        $this->container['subtaskModel']->create(['task_id' => $id, 'title' => 'Reproduce']);
        $this->container['subtaskModel']->create(['task_id' => $id, 'title' => 'Patch', 'status' => 2]);
        for ($i = 1; $i <= 25; $i++) {
            $this->comment($id, "note $i");
        }
        $this->comment($id, 'admins only', Role::APP_ADMIN);

        $data = $this->json($this->tool(GetTaskTool::class)->execute(['task_id' => $id]));

        $task = $data['task'];
        $this->assertSame(1, $task['n']);
        $this->assertSame([$id, 'Fix login', 'Users cannot log in.', 'open'], [$task['task_id'], $task['title'], $task['description'], $task['status']]);
        $this->assertSame([['title' => 'Reproduce', 'status' => 'todo'], ['title' => 'Patch', 'status' => 'done']], $task['subtasks']);
        $this->assertCount(20, $task['comments']);
        $this->assertSame('note 6', $task['comments'][0]['text'], 'the last 20, oldest first');
        $this->assertSame('note 25', $task['comments'][19]['text']);
        $this->assertSame('Ana', $task['comments'][19]['author']);
        $this->assertStringNotContainsString('admins only', json_encode($data), 'comment visibility follows the asker');

        $source = $this->sources->resolve(1);
        $this->assertSame(['task', (string) $id, 'Fix login'], [$source->kind, $source->ref, $source->title]);
        $this->assertStringStartsWith('/', $source->url);
        $this->assertStringContainsString("task_id=$id", $source->url);
    }

    public function testSearchTasksScopedToRoomProject(): void
    {
        $a = $this->task($this->room, 'Login page redesign');
        $b = $this->task($this->room, 'Billing', 'The login form breaks on Safari');
        $closed = $this->task($this->room, 'Old login bug');
        $this->container['taskStatusModel']->close($closed);
        $this->task($this->room, 'Unrelated');
        $this->task($this->other, 'Login secrets');
        $search = $this->tool(SearchTasksTool::class);

        $results = $this->json($search->execute(['query' => 'login']))['results'];
        $ids = array_column($results, 'task_id');
        sort($ids);
        $this->assertSame([$a, $b, $closed], $ids);
        $this->assertSame(range(1, 3), array_column($results, 'n'));
        $this->assertStringNotContainsString('secrets', json_encode($results));

        $open = $this->json($search->execute(['query' => 'login', 'status' => 'open']))['results'];
        $this->assertEqualsCanonicalizing([$a, $b], array_column($open, 'task_id'));
        $this->assertSame([$closed], array_column($this->json($search->execute(['query' => 'login', 'status' => 'closed']))['results'], 'task_id'));
        $this->assertCount(1, $this->json($search->execute(['query' => 'login', 'limit' => 1]))['results']);

        // In a task drawer, that task comes first (otherwise newest first, so it would be last).
        $this->assertSame($a, end($results)['task_id']);
        $drawer = $this->tool(SearchTasksTool::class, $this->scope(taskId: $a));
        $this->assertSame($a, $this->json($drawer->execute(['query' => 'login']))['results'][0]['task_id']);

        $this->assertNotFound($this->tool(SearchTasksTool::class, $this->scope($this->user('otto')))->execute(['query' => 'login']));
    }

    public function testSearchCommentsLimit(): void
    {
        $id = $this->task($this->room, 'Fix login');
        for ($i = 1; $i <= 15; $i++) {
            $this->comment($id, "deploy note $i");
        }
        $this->comment($id, 'deploy keys for managers', Role::APP_MANAGER);
        $this->comment($this->task($this->other, 'Elsewhere'), 'deploy elsewhere');
        $search = $this->tool(SearchCommentsTool::class);

        $results = $this->json($search->execute(['query' => 'deploy']))['results'];
        $this->assertCount(10, $results);
        $this->assertSame('deploy note 15', $results[0]['text'], 'newest first');
        $this->assertSame([$id, 'Fix login', 'Ana'], [$results[0]['task_id'], $results[0]['task_title'], $results[0]['author']]);
        $this->assertArrayHasKey('comment_id', $results[0]);
        $this->assertArrayHasKey('created', $results[0]);
        $this->assertStringNotContainsString('elsewhere', json_encode($results));
        $this->assertStringNotContainsString('managers', json_encode($results));

        $this->assertCount(3, $this->json($search->execute(['query' => 'deploy', 'limit' => 3]))['results']);
        $this->assertSame(ToolResultStatus::Error, $search->execute(['query' => 'deploy', 'limit' => 11])->status);

        $source = $this->sources->resolve(1);
        $this->assertSame(['comment', (string) $results[0]['comment_id']], [$source->kind, $source->ref]);
        $this->assertStringEndsWith('#comment-'.$results[0]['comment_id'], $source->url);
    }

    public function testKnowledgeResultsRegisterSources(): void
    {
        $knowledge = new KnowledgeModel($this->container);
        $doc = $knowledge->upsert($this->room, 'save-it', 'p1', ['title' => 'Founder interview', 'body' => 'The autoplay hero converts.', 'url' => 'https://example.com/p1']);
        $knowledge->upsert($this->other, 'save-it', 'p2', ['title' => 'Other autoplay', 'body' => 'autoplay elsewhere', 'url' => 'https://example.com/p2']);
        $search = $this->tool(SearchKnowledgeTool::class);

        $results = $this->json($search->execute(['query' => 'autoplay']))['results'];
        $this->assertCount(1, $results);
        $this->assertSame([1, $doc, 'Founder interview', 'save-it'], [$results[0]['n'], $results[0]['knowledge_id'], $results[0]['title'], $results[0]['source']]);
        $this->assertStringContainsString('autoplay', $results[0]['snippet']);

        $source = $this->sources->resolve(1);
        $this->assertSame(['knowledge', (string) $doc, 'Founder interview'], [$source->kind, $source->ref, $source->title]);
        $this->assertStringStartsWith('/', $source->url);
        $this->assertStringContainsString('KnowledgeController', $source->url);

        $this->assertSame([], $this->json($search->execute(['query' => 'autoplay', 'source' => 'notes']))['results']);
        $this->assertNotFound($this->tool(SearchKnowledgeTool::class, $this->scope($this->user('otto')))->execute(['query' => 'autoplay']));
    }

    public function testAskerWhoLostAccessGetsNotFound(): void
    {
        $id = $this->task($this->room, 'Fix login');
        $scope = $this->scope();
        $this->container['projectUserRoleModel']->removeUser($this->room, $this->asker);
        $this->container['memoryCache']->flush();

        $this->assertFalse($scope->canReadTask($id));
        $this->assertNotFound($this->tool(GetTaskTool::class, $scope)->execute(['task_id' => $id]));
        $this->assertNotFound($this->tool(SearchCommentsTool::class, $scope)->execute(['query' => 'login']));
        $this->assertSame([], $this->sources->all());
    }

    public function testUnknownArgumentRejected(): void
    {
        foreach ([SearchTasksTool::class, GetTaskTool::class, SearchCommentsTool::class, SearchKnowledgeTool::class] as $class) {
            $tool = $this->tool($class);
            $schema = $tool->toFunctionSchema()['function']['parameters'];
            $this->assertFalse($schema['additionalProperties'], $class);
            $this->assertNotEmpty($schema['required'], $class);

            $result = $tool->execute(['query' => 'x', 'task_id' => 1, 'project_id' => $this->other]);
            $this->assertSame(ToolResultStatus::Error, $result->status, $class);
            $this->assertStringContainsString('Unknown parameters', $result->content, $class);
        }

        $this->assertSame(['task_id'], $this->tool(GetTaskTool::class)->toFunctionSchema()['function']['parameters']['required']);
        $this->assertSame(1.0, (float) $this->tool(GetTaskTool::class)->toFunctionSchema()['function']['parameters']['properties']['task_id']['minimum']);
        $this->assertSame(8.0, (float) $this->tool(SearchKnowledgeTool::class)->toFunctionSchema()['function']['parameters']['properties']['limit']['maximum']);
    }

    public function testInvalidArgumentsAreErrorResults(): void
    {
        $getTask = $this->tool(GetTaskTool::class);
        $search = $this->tool(SearchTasksTool::class);

        foreach ([
            $getTask->execute([]),
            $getTask->execute(['task_id' => 0]),
            $getTask->execute(['task_id' => 'abc']),
            $getTask->execute(['task_id' => [1]]),
            $search->execute(['query' => ['login']]),
            $search->execute(['query' => 'login', 'limit' => 0]),
            $search->execute(['query' => 'login', 'status' => 'deleted']),
            $search->execute(['query' => str_repeat('a', 201)]),
        ] as $i => $result) {
            $this->assertSame(ToolResultStatus::Error, $result->status, "case $i");
        }
    }

    public function testTextIsCleanedAndTruncated(): void
    {
        $id = $this->task($this->room, "Fix\x07 login\u{202E}", "line one\r\nline\ttwo\x1b[31m".str_repeat('é', 3000));

        $task = $this->json($this->tool(GetTaskTool::class)->execute(['task_id' => $id]))['task'];

        $this->assertSame('Fix login', $task['title']);
        $this->assertStringStartsWith("line one\nline\ttwo[31m", $task['description']);
        $this->assertSame(2000, mb_strlen($task['description'], 'UTF-8'));
        $this->assertStringEndsWith('é…', $task['description']);
    }
}
