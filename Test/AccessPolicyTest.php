<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;

class AccessPolicyTest extends Base
{
    private int $projectId;
    private AccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectId = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->policy = new AccessPolicy($this->container);
    }

    private function user(string $name, string $appRole = Role::APP_USER): int
    {
        return (int) $this->container['userModel']->create(['username' => $name, 'role' => $appRole]);
    }

    private function member(string $name, string $projectRole): int
    {
        $id = $this->user($name);
        $this->container['projectUserRoleModel']->addUser($this->projectId, $id, $projectRole);
        return $id;
    }

    /** @return array{0: bool, 1: bool, 2: bool, 3: bool} view, post, ask, manage */
    private function rights(int $userId, ?int $projectId = null): array
    {
        $p = $projectId ?? $this->projectId;
        return [$this->policy->canView($userId, $p), $this->policy->canPost($userId, $p), $this->policy->canAsk($userId, $p), $this->policy->canManage($userId, $p)];
    }

    public function testOutsiderCannotViewOrPost(): void
    {
        $outsider = $this->user('outsider');
        $this->assertSame([false, false, false, false], $this->rights($outsider));

        // An app manager is no project member either.
        $this->assertSame([false, false, false, false], $this->rights($this->user('appmanager', Role::APP_MANAGER)));
        // Unknown user.
        $this->assertSame([false, false, false, false], $this->rights(999));
        // A non-private project still needs a role (Kanboard has no "everybody allowed" any more).
        $this->assertFalse($this->container['projectModel']->isPrivate($this->projectId));
    }

    public function testViewerCanViewButNotPost(): void
    {
        $viewer = $this->member('viewer', Role::PROJECT_VIEWER);
        $this->assertSame([true, false, false, false], $this->rights($viewer));
    }

    public function testMemberAndManagerRights(): void
    {
        $this->assertSame([true, true, true, false], $this->rights($this->member('member', Role::PROJECT_MEMBER)));
        $this->assertSame([true, true, true, true], $this->rights($this->member('manager', Role::PROJECT_MANAGER)));
    }

    public function testGroupRoleCounts(): void
    {
        $id = $this->user('grouped');
        $groupId = (int) $this->container['groupModel']->create('Team');
        $this->container['groupMemberModel']->addUser($groupId, $id);
        $this->container['projectGroupRoleModel']->addGroup($this->projectId, $groupId, Role::PROJECT_MEMBER);

        $this->assertSame([true, true, true, false], $this->rights($id));
    }

    public function testCustomProjectRoleCountsAsMember(): void
    {
        $this->container['projectRoleModel']->create($this->projectId, 'Contractor');
        $this->assertSame([true, true, true, false], $this->rights($this->member('contractor', 'Contractor')));
    }

    public function testInactiveUserAndMissingProjectAreDenied(): void
    {
        $manager = $this->member('manager', Role::PROJECT_MANAGER);
        $this->assertSame([false, false, false, false], $this->rights($manager, 999));

        $this->container['userModel']->disable($manager);
        $this->assertSame([false, false, false, false], $this->rights($manager));

        $admin = $this->user('admin2', Role::APP_ADMIN);
        $this->container['userModel']->disable($admin);
        $this->assertSame([false, false, false, false], $this->rights($admin));
    }

    public function testAppAdminCanPostEverywhere(): void
    {
        $admin = $this->user('admin2', Role::APP_ADMIN);
        $private = (int) $this->container['projectModel']->create(['name' => 'Mine', 'is_private' => 1], $this->user('owner'), true);

        $this->assertSame([true, true, true, true], $this->rights($admin));
        $this->assertSame([true, true, true, true], $this->rights($admin, $private));
        (new ProjectSettings($this->container, $this->projectId))->setAskRole('managers');
        $this->assertTrue($this->policy->canAsk($admin, $this->projectId));
        $this->assertFalse($this->policy->canView($admin, 999));
    }

    public function testAskRoleMatrix(): void
    {
        $manager = $this->member('manager', Role::PROJECT_MANAGER);
        $member = $this->member('member', Role::PROJECT_MEMBER);
        $viewer = $this->member('viewer', Role::PROJECT_VIEWER);
        $settings = new ProjectSettings($this->container, $this->projectId);

        $expected = [
            'managers' => [true, false, false],
            'members' => [true, true, false],
            'everyone' => [true, true, true],
        ];
        foreach ($expected as $askRole => $row) {
            $settings->setAskRole($askRole);
            $this->assertSame($row, [
                $this->policy->canAsk($manager, $this->projectId),
                $this->policy->canAsk($member, $this->projectId),
                $this->policy->canAsk($viewer, $this->projectId),
            ], $askRole);
        }

        // An unknown stored value falls back to the default, members.
        $this->container['projectMetadataModel']->save($this->projectId, ['agentchat_ask_role' => 'bogus']);
        $this->assertSame('members', $settings->askRole());
        $this->assertSame([true, true, false], [
            $this->policy->canAsk($manager, $this->projectId),
            $this->policy->canAsk($member, $this->projectId),
            $this->policy->canAsk($viewer, $this->projectId),
        ]);
    }

    public function testProjectSettingsDefaultsAndStorage(): void
    {
        $settings = new ProjectSettings($this->container, $this->projectId);
        $this->assertTrue($settings->botEnabled());
        $this->assertSame('members', $settings->askRole());
        $this->assertNull($settings->aiProfile());
        $this->assertTrue($settings->botImages());

        $settings->setBotEnabled(false);
        $settings->setBotImages(false);
        $settings->setAiProfile('p1');
        $settings->setAskRole('everyone');

        $meta = $this->container['projectMetadataModel']->getAll($this->projectId);
        $this->assertSame('0', (string) $meta['agentchat_bot_enabled']);
        $this->assertSame('0', (string) $meta['agentchat_bot_images']);
        $this->assertSame('p1', $meta['agentchat_ai_profile']);
        $this->assertSame('everyone', $meta['agentchat_ask_role']);

        $fresh = new ProjectSettings($this->container, $this->projectId);
        $this->assertFalse($fresh->botEnabled());
        $this->assertFalse($fresh->botImages());
        $this->assertSame('p1', $fresh->aiProfile());
        $this->assertSame('everyone', $fresh->askRole());

        $fresh->setBotEnabled(true);
        $this->assertSame('1', (string) $this->container['projectMetadataModel']->get($this->projectId, 'agentchat_bot_enabled'));
        $this->assertTrue($fresh->botEnabled());

        $fresh->setAiProfile('');
        $this->assertNull($fresh->aiProfile());
        $fresh->setAiProfile(null);
        $this->assertNull($fresh->aiProfile());

        // Settings are per project.
        $other = (int) $this->container['projectModel']->create(['name' => 'Other']);
        $this->assertTrue((new ProjectSettings($this->container, $other))->botEnabled());

        $this->expectException(InvalidArgumentException::class);
        $fresh->setAskRole('admins');
    }
}
