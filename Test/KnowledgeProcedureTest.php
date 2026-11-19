<?php
require_once 'tests/units/Base.php';

use JsonRPC\Exception\AccessDeniedException;
use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Api\AgentChatKnowledgeProcedure;
use Kanboard\Plugin\AgentChat\Model\KnowledgeModel;

class KnowledgeProcedureTest extends Base
{
    private int $project;
    private int $member;
    private int $manager;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Archive']);
        $users = $this->container['userModel'];
        $this->member = (int) $users->create(['username' => 'mia']);
        $this->manager = (int) $users->create(['username' => 'max']);
        $this->admin = (int) $users->create(['username' => 'ada', 'role' => Role::APP_ADMIN]);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->member, Role::PROJECT_MEMBER);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->manager, Role::PROJECT_MANAGER);
    }

    private function actAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function rpc(): AgentChatKnowledgeProcedure
    {
        return new AgentChatKnowledgeProcedure($this->container);
    }

    private function upsert(int|string $projectId = 0, string $externalId = 'post-1')
    {
        return $this->rpc()->knowledgeUpsert($projectId ?: $this->project, 'save-it', $externalId, 'Founder interview', 'The autoplay hero video.', 'https://www.instagram.com/p/abc/', 'founder', 1763500000, ['likes' => 3]);
    }

    private function rows(): int
    {
        return (int) $this->container['db']->table(KnowledgeModel::TABLE)->count();
    }

    private function assertDenied(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected AccessDeniedException');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testMemberCannotUpsert(): void
    {
        $this->actAs($this->member);
        $this->assertDenied(fn () => $this->upsert());
        $this->assertDenied(fn () => $this->rpc()->knowledgeDelete($this->project, 'save-it', 'post-1'));
        $this->assertSame(0, $this->rows());

        $outsider = (int) $this->container['userModel']->create(['username' => 'otto']);
        $this->actAs($outsider);
        $this->assertDenied(fn () => $this->upsert());
        // A project that does not exist looks the same as one the user cannot manage.
        $this->assertDenied(fn () => $this->upsert(9999));
        $this->assertSame(0, $this->rows());
    }

    public function testInactiveManagerCannotUpsert(): void
    {
        $this->actAs($this->manager);
        $this->container['userModel']->disable($this->manager);
        $this->container['memoryCache']->flush();
        $this->assertDenied(fn () => $this->upsert());
        $this->assertSame(0, $this->rows());
    }

    public function testManagerCanUpsert(): void
    {
        $this->actAs($this->manager);
        $result = $this->upsert();
        $this->assertSame(['ok' => true, 'id' => $result['id']], $result);
        $this->assertSame($result, $this->upsert((string) $this->project));
        $this->assertSame(1, $this->rows());
        $row = (new KnowledgeModel($this->container))->getById($result['id']);
        $this->assertSame('Founder interview', $row['title']);
        $this->assertSame('founder', $row['author']);

        $this->assertTrue($this->rpc()->knowledgeDelete($this->project, 'save-it', 'post-1'));
        $this->assertFalse($this->rpc()->knowledgeDelete($this->project, 'save-it', 'post-1'));
        $this->assertSame(0, $this->rows());
    }

    public function testAppAdminCanUpsert(): void
    {
        $this->actAs($this->admin);
        $this->assertTrue($this->upsert()['ok']);
        $this->assertTrue($this->rpc()->knowledgeDelete($this->project, 'save-it', 'post-1'));
    }

    public function testApplicationTokenCanUpsertIntoAnExistingProject(): void
    {
        // No user session: Kanboard's application token (user "jsonrpc") has full API access.
        $this->assertFalse($this->container['userSession']->isLogged());
        $this->assertTrue($this->upsert()['ok']);
        $this->assertSame(1, $this->rows());
        try {
            $this->upsert(9999);
            $this->fail('Expected InvalidArgumentException for an unknown project');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Unknown project.', $e->getMessage());
        }
    }

    public function testInvalidParamsAreInvalidArgumentWithPlainMessages(): void
    {
        $this->actAs($this->manager);
        $calls = [
            fn () => $this->rpc()->knowledgeUpsert('abc', 'save-it', 'x', 'T', 'B'),
            fn () => $this->rpc()->knowledgeUpsert($this->project, 'save-it', 'x', '', 'B'),
            fn () => $this->rpc()->knowledgeUpsert($this->project, 'save-it', 'x', 'T', 'B', 'javascript:alert(1)'),
            fn () => $this->rpc()->knowledgeUpsert($this->project, 'save-it', 'x', 'T', 'B', '', '', 0, 'not-an-object'),
            fn () => $this->rpc()->knowledgeUpsert($this->project, ['a'], 'x', 'T', 'B'),
            fn () => $this->rpc()->knowledgeUpsert($this->project, 'save-it', 'x', 'T', str_repeat('b', 200001)),
            fn () => $this->rpc()->knowledgeDelete($this->project, 'save-it', ''),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("Call #$i should be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertDoesNotMatchRegularExpression('/SQL|SELECT|INSERT|\.php|#\d+ /i', $e->getMessage(), "Call #$i");
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, $this->rows());
    }

    public function testStorageFailureIsAPlainError(): void
    {
        $this->actAs($this->manager);
        $this->container['db']->getConnection()->exec('DROP TABLE agentchat_knowledge'.(DB_DRIVER === 'postgres' ? ' CASCADE' : ''));
        try {
            $this->upsert();
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('The knowledge store could not save this document.', $e->getMessage());
        }
    }

    public function testProcedureClassDeclaresOnlyItsRpcMethodsWithUntypedParams(): void
    {
        $class = new ReflectionClass(AgentChatKnowledgeProcedure::class);
        $own = array_values(array_map(fn ($m) => $m->getName(), array_filter($class->getMethods(), fn ($m) => $m->getDeclaringClass()->getName() === $class->getName())));
        sort($own);
        $this->assertSame(['knowledgeDelete', 'knowledgeUpsert'], $own);
        foreach ($own as $name) {
            foreach ($class->getMethod($name)->getParameters() as $param) {
                $this->assertFalse($param->hasType(), "$name(\${$param->getName()}) must not be typed");
            }
        }
        $this->assertSame(['project_id', 'source', 'external_id', 'title', 'body', 'url', 'author', 'occurred_at', 'metadata'], array_map(fn ($p) => $p->getName(), $class->getMethod('knowledgeUpsert')->getParameters()));
        $this->assertFileDoesNotExist('app/Api/Procedure/AgentChatKnowledgeProcedure.php');
    }

    public function testPluginRegistersTheProcedures(): void
    {
        $this->container->register(new \Kanboard\ServiceProvider\ApiProvider());
        (new \Kanboard\Plugin\AgentChat\Plugin($this->container))->initialize();
        $handler = $this->container['api']->getProcedureHandler();
        $this->actAs($this->manager);
        $result = $handler->executeProcedure('knowledgeUpsert', ['project_id' => $this->project, 'source' => 'save-it', 'external_id' => 'p', 'title' => 'T', 'body' => 'B']);
        $this->assertTrue($result['ok']);
    }
}
