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

    public function testGetTaskCapsSubtasks(): void
    {
        $id = $this->task($this->room, 'Checklist');
        for ($i = 1; $i <= 60; $i++) {
            $this->container['subtaskModel']->create(['task_id' => $id, 'title' => "step $i"]);
        }
        $this->comment($id, 'only one');

        $data = $this->json($this->tool(GetTaskTool::class)->execute(['task_id' => $id]));

        $this->assertCount(50, $data['task']['subtasks']);
        $this->assertSame('step 50', $data['task']['subtasks'][49]['title'], 'the first 50 in position order');
        $this->assertSame(60, $data['task']['subtasks_total']);
        $this->assertSame(1, $data['task']['comments_total']);
        $this->assertTrue($data['truncated']);
    }

    public function testGetTaskFitsTheSizeCap(): void
    {
        $id = $this->task($this->room, str_repeat('T', 3000), str_repeat('D', 3000));
        for ($i = 1; $i <= 60; $i++) {
            $this->container['subtaskModel']->create(['task_id' => $id, 'title' => str_repeat('s', 3000)]);
        }
        for ($i = 1; $i <= 22; $i++) {
            $this->comment($id, sprintf('%02d', $i).str_repeat('c', 3000));
        }

        $result = $this->tool(GetTaskTool::class)->execute(['task_id' => $id]);
        $data = $this->json($result);

        $this->assertLessThanOrEqual(24000, mb_strlen($result->content, 'UTF-8'));
        $this->assertTrue($data['truncated']);
        $this->assertSame([], $data['task']['comments'], 'comments go first');
        $this->assertNotEmpty($data['task']['subtasks']);
        $this->assertSame([60, 22], [$data['task']['subtasks_total'], $data['task']['comments_total']]);
        $this->assertSame(1, $data['task']['n']);
        $this->assertSame(2000, mb_strlen($data['task']['description'], 'UTF-8'));

        // With only comments over the cap, the oldest are dropped and the newest kept.
        $other = $this->task($this->room, 'Chatty');
        for ($i = 1; $i <= 20; $i++) {
            $this->comment($other, sprintf('%02d', $i).str_repeat('c', 3000));
        }
        $result = $this->tool(GetTaskTool::class)->execute(['task_id' => $other]);
        $comments = $this->json($result)['task']['comments'];
        $this->assertLessThanOrEqual(24000, mb_strlen($result->content, 'UTF-8'));
        $this->assertGreaterThan(0, count($comments));
        $this->assertLessThan(20, count($comments));
        $this->assertStringStartsWith('20', end($comments)['text']);
    }

    public function testSearchResultsFitTheSizeCap(): void
    {
        $id = $this->task($this->room, str_repeat('T', 3000));
        for ($i = 1; $i <= 10; $i++) {
            $this->comment($id, 'deploy '.str_repeat('c', 3000));
        }

        $result = $this->tool(SearchCommentsTool::class)->execute(['query' => 'deploy']);
        $data = $this->json($result);

        $this->assertLessThanOrEqual(24000, mb_strlen($result->content, 'UTF-8'));
        $this->assertTrue($data['truncated']);
        $this->assertSame(10, $data['results_total']);
        $this->assertGreaterThan(0, count($data['results']));
        $this->assertLessThan(10, count($data['results']));
        $this->assertCount(count($data['results']), $this->sources->all(), 'only items shown are numbered');
    }

    public function testStorageFailureTextNeverReachesTheModel(): void
    {
        $logged = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) use (&$logged) {
            $logged[] = (string) $message;
        });
        $this->container['logger'] = $logger;
        $this->task($this->room, 'Fix login');
        $this->container['db']->getConnection()->exec('DROP TABLE comments');

        $result = $this->tool(SearchCommentsTool::class)->execute(['query' => 'login']);

        $this->assertSame(ToolResultStatus::Error, $result->status);
        $this->assertSame('The lookup failed.', $result->content);
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('SQLSTATE', $logged[0], 'the real error is logged');
        $this->assertStringContainsString('search_comments', $logged[0]);
    }

    public function testLikeMetacharactersMatchNothing(): void
    {
        $this->task($this->room, '100% done');
        $this->task($this->room, 'snake_case');
        $this->comment($this->task($this->room, 'Other'), '50% off_peak');

        foreach (['%', '_', '%_%', '\\'] as $query) {
            $this->assertSame([], $this->json($this->tool(SearchTasksTool::class)->execute(['query' => $query]))['results'], $query);
            $this->assertSame([], $this->json($this->tool(SearchCommentsTool::class)->execute(['query' => $query]))['results'], $query);
        }
        $this->assertCount(1, $this->json($this->tool(SearchTasksTool::class)->execute(['query' => '100%']))['results'], 'the word still matches');
    }

    public function testGroupMemberReadsUntilRemovedFromTheGroup(): void
    {
        $id = $this->task($this->room, 'Fix login');
        $gus = $this->user('gus');
        $group = (int) $this->container['groupModel']->create('Team');
        $this->container['groupMemberModel']->addUser($group, $gus);
        $this->container['projectGroupRoleModel']->addGroup($this->room, $group, Role::PROJECT_MEMBER);
        $scope = $this->scope($gus);

        $this->assertTrue($scope->canReadTask($id));
        $this->assertSame($id, $this->json($this->tool(GetTaskTool::class, $scope)->execute(['task_id' => $id]))['task']['task_id']);

        $this->container['groupMemberModel']->removeUser($group, $gus);
        $this->assertNotFound($this->tool(GetTaskTool::class, $scope)->execute(['task_id' => $id]));
        $this->assertNotFound($this->tool(SearchTasksTool::class, $scope)->execute(['query' => 'login']));
    }

    public function testInactiveAskerGetsNotFound(): void
    {
        $id = $this->task($this->room, 'Fix login');
        $scope = $this->scope();
        $this->container['userModel']->disable($this->asker);

        $this->assertNotFound($this->tool(GetTaskTool::class, $scope)->execute(['task_id' => $id]));
    }

    public function testTextTurnsLineSeparatorsIntoNewlines(): void
    {
        $this->assertSame("a\nb\nc", \Kanboard\Plugin\AgentChat\Bot\Tools\ReadOnlyTool::text("a\u{2028}b\u{2029}c"));
    }

    /** Text whose JSON escapes are long: quotes, backslashes, line separators, a control char next to them. */
    private static function escapeHeavy(int $chars): string
    {
        return mb_substr(str_repeat("\"\\\u{2028}\x01\u{2029}", $chars), 0, $chars, 'UTF-8');
    }

    private function assertFitsCap(ToolResult $result, int $cap = 24000): array
    {
        $this->assertLessThanOrEqual($cap, mb_strlen($result->content, 'UTF-8'));
        return $this->json($result); // valid JSON, marked untrusted
    }

    public function testEscapeHeavyGetTaskFitsTheCap(): void
    {
        $id = $this->task($this->room, str_repeat("\u{2028}", 3000), str_repeat("\u{2029}", 3000)); // ~12 000 escaped chars each
        for ($i = 1; $i <= 60; $i++) {
            $this->container['subtaskModel']->create(['task_id' => $id, 'title' => self::escapeHeavy(3000)]);
        }
        for ($i = 1; $i <= 22; $i++) {
            $this->comment($id, self::escapeHeavy(3000));
        }

        $data = $this->assertFitsCap($this->tool(GetTaskTool::class)->execute(['task_id' => $id]));

        $this->assertTrue($data['truncated']);
        $this->assertSame(1, $data['task']['n']);
        $this->assertStringNotContainsString("\u{2028}", $data['task']['title']);
    }

    public function testEscapeHeavySearchFitsTheCap(): void
    {
        $id = $this->task($this->room, str_repeat("\u{2028}", 3000));
        for ($i = 1; $i <= 10; $i++) {
            $this->comment($id, 'deploy '.self::escapeHeavy(3000));
        }

        $data = $this->assertFitsCap($this->tool(SearchCommentsTool::class)->execute(['query' => 'deploy']));

        $this->assertTrue($data['truncated']);
        $this->assertNotEmpty($data['results']);
        $this->assertCount(count($data['results']), $this->sources->all());
    }

    public function testOversizedSingleSearchItemIsShortenedNotDropped(): void
    {
        $this->comment($this->task($this->room, 'Fix login'), 'deploy '.str_repeat('"', 2000));
        $tool = new class ($this->scope(), $this->sources) extends SearchCommentsTool {
            public const MAX_RESULT = 3000;
        };

        $data = $this->assertFitsCap($tool->execute(['query' => 'deploy']), 3000);

        $this->assertCount(1, $data['results']);
        $this->assertTrue($data['truncated']);
        $this->assertSame(1, $data['results'][0]['n']);
        $this->assertStringStartsWith('deploy "', $data['results'][0]['text']);
        $this->assertStringEndsWith('…', $data['results'][0]['text']);
        $this->assertSame('Fix login', $data['results'][0]['task_title']);
    }

    public function testGetTaskShortensDescriptionFirst(): void
    {
        $id = $this->task($this->room, 'Fix login', str_repeat('"', 2000));
        $tool = new class ($this->scope(), $this->sources) extends GetTaskTool {
            public const MAX_RESULT = 3000;
        };

        $data = $this->assertFitsCap($tool->execute(['task_id' => $id]), 3000);

        $this->assertTrue($data['truncated']);
        $this->assertSame('Fix login', $data['task']['title']);
        $this->assertStringEndsWith('…', $data['task']['description']);
        $this->assertSame(1, $data['task']['n']);
    }
}
