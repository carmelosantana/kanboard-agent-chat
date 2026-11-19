<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Controller\KnowledgeController;
use Kanboard\Plugin\AgentChat\Model\KnowledgeModel;

class KnowledgeControllerTest extends Base
{
    private int $project;
    private int $viewer;
    private int $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../vendor-chatparts/autoload.php'; // KanboardHost implements the kit's HostAdapter
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Archive']);
        $other = (int) $this->container['projectModel']->create(['name' => 'Other']);
        $this->viewer = (int) $this->container['userModel']->create(['username' => 'vic']);
        $this->outsider = (int) $this->container['userModel']->create(['username' => 'otto']);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->viewer, Role::PROJECT_VIEWER);
        $this->container['projectUserRoleModel']->addUser($other, $this->outsider, Role::PROJECT_MANAGER);
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function store(array $doc = []): int
    {
        return (new KnowledgeModel($this->container))->upsert($this->project, 'save-it', 'post-1', $doc + [
            'title' => 'Founder <interview>',
            'body' => "## Highlights\n\nThe **autoplay** hero.\n\n<script>alert(1)</script> [bad](javascript:alert(2))",
            'url' => 'https://www.instagram.com/p/abc/',
            'author' => 'founder',
            'occurred_at' => 1763500000,
        ]);
    }

    private function show(int $id, int &$status = 0): string
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['id' => (string) $id], [], [], []);
        $out = '';
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($data, $code = 200) use (&$out, &$status) {
            $out = (string) $data;
            $status = $code;
        });
        $this->container['response'] = $resp;
        (new KnowledgeController($this->container))->show();
        return $out;
    }

    public function testViewerSeesTheDocumentSanitised(): void
    {
        $id = $this->store();
        $this->loginAs($this->viewer);
        $status = 0;
        $html = $this->show($id, $status);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Founder &lt;interview&gt;', $html);
        $this->assertStringContainsString('save-it', $html);
        $this->assertStringContainsString('founder', $html);
        $this->assertStringContainsString('<strong>autoplay</strong>', $html);
        $this->assertStringContainsString('href="https://www.instagram.com/p/abc/" rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('javascript:alert(2)', $html);
        $this->assertStringNotContainsString('Founder <interview>', $html);
    }

    public function testNonHttpUrlIsNotLinked(): void
    {
        $id = $this->store();
        // Rows written before validation existed, or by hand, must still not become links.
        $this->container['db']->table(KnowledgeModel::TABLE)->eq('id', $id)->update(['url' => 'javascript:alert(3)']);
        $this->loginAs($this->viewer);
        $this->assertStringNotContainsString('javascript:alert(3)', $this->show($id));
    }

    public function testOutsiderIsForbidden(): void
    {
        $id = $this->store();
        $this->loginAs($this->outsider);
        $this->expectException(AccessForbiddenException::class);
        $this->show($id);
    }

    public function testMissingDocumentIsNotFound(): void
    {
        $this->loginAs($this->viewer);
        // Core's PageNotFoundException page answers 200; this one says 404.
        $status = 0;
        $html = $this->show(424242, $status);
        $this->assertSame(404, $status);
        $this->assertStringContainsString('Page not found', $html);
    }

    public function testRouteIsRegistered(): void
    {
        $this->container->register(new \Kanboard\ServiceProvider\ApiProvider());
        $this->container['route']->enable(); // addRoute() is a no-op until routing is enabled
        (new \Kanboard\Plugin\AgentChat\Plugin($this->container))->initialize();
        $this->assertSame(
            ['controller' => 'KnowledgeController', 'action' => 'show', 'plugin' => 'AgentChat'],
            $this->container['route']->findRoute('agentchat/knowledge/7')
        );
        $this->assertSame(7, $this->container['request']->getIntegerParam('id'));
    }
}
