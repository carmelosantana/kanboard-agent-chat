<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Plugin;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

class AiGateTest extends Base
{
    public function testFalseBelowPhp84(): void
    {
        $this->configureProfile();
        $this->assertFalse(AiGate::isReady($this->container, 80399, true, '1.4.0', true));
    }

    public function testFalseWhenConnectorAbsent(): void
    {
        $this->configureProfile();
        $this->assertFalse(AiGate::isReady($this->container, 80400, false, '1.4.0', true));
    }

    public function testFalseWhenConnectorTooOld(): void
    {
        $this->configureProfile();
        $this->assertFalse(AiGate::isReady($this->container, 80400, true, '1.1.6', true));
        $this->assertFalse(AiGate::isReady($this->container, 80400, true, '1.3.0', true), 'routing came in 1.4.0');
        $this->assertTrue(AiGate::isReady($this->container, 80400, true, '1.10.0', true));
    }

    public function testFalseWhenPhpAgentsLacksRunLimits(): void
    {
        $this->configureProfile();
        $this->assertFalse(AiGate::isReady($this->container, 80400, true, '1.4.0', false));
    }

    public function testFalseWhenNoProviderConfigured(): void
    {
        $this->assertFalse(AiGate::isReady($this->container, 80400, true, '1.4.0', true));
    }

    public function testTrueWhenEveryBranchHolds(): void
    {
        $this->configureProfile();
        $this->assertTrue(AiGate::isReady($this->container, 80400, true, '1.4.0', true));
    }

    public function testReadsTheInstalledConnectorVersionByDefault(): void
    {
        if (! class_exists(\Kanboard\Plugin\AiConnector\Plugin::class)) {
            $this->markTestSkipped('AiConnector checkout not present');
        }
        $this->configureProfile();
        $installed = (new \Kanboard\Plugin\AiConnector\Plugin($this->container))->getPluginVersion();
        $this->assertSame(
            version_compare($installed, AiGate::MIN_CONNECTOR_VERSION, '>='),
            AiGate::isReady($this->container, 80400, true, null, true)
        );
    }

    public function testFalseWithoutTheRoutingApi(): void
    {
        $this->configureProfile();
        $this->assertFalse(AiGate::isReady($this->container, 80400, true, '1.4.0', true, false));
        $this->assertTrue(AiGate::isReady($this->container, 80400, true, '1.4.0', true, true));
    }

    public function testRegistersTheBotWithAiConnectorRouting(): void
    {
        $this->container->register(new \Kanboard\ServiceProvider\ApiProvider());
        (new Plugin($this->container))->initialize();

        $this->assertSame(
            ['id' => 'agentchat.bot', 'label' => 'Chat bot', 'plugin' => 'AgentChat'],
            (new ProviderRegistry($this->container))->features()[AiGate::FEATURE] ?? null
        );
        $this->assertSame('agentchat.bot', AiGate::FEATURE);
    }

    public function testTheBotFollowsTheProjectRouteNotTheOldBotProfile(): void
    {
        $this->routedProfiles(defaultKey: 'sk-test-fake-key-1');
        $project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        // The removed per-project bot profile: stored values are left in place and ignored.
        $this->container['projectMetadataModel']->save($project, ['agentchat_ai_profile' => 'p2']);
        $this->assertSame('p1', AiGate::botProfile($this->container, $project));

        $this->container['projectMetadataModel']->save($project, ['aiconnector_project_profile' => 'p2']);
        $this->assertSame('p2', AiGate::botProfile($this->container, $project));
    }

    public function testNoReadyRouteClosesTheGateForTheProject(): void
    {
        // The default (p1) has no key, so routing has nothing ready, though p2 is.
        $this->routedProfiles(defaultKey: '');
        $project = (int) $this->container['projectModel']->create(['name' => 'Website']);

        $this->assertTrue(AiGate::isReady($this->container), 'a provider is ready on the instance');
        $this->assertSame('', AiGate::botProfile($this->container, $project));
        $this->assertFalse(AiGate::isReadyFor($this->container, $project));
        $this->assertFalse(AiGate::isOpenFor($this->container, $project));

        $this->container['projectMetadataModel']->save($project, ['aiconnector_project_profile' => 'p2']);
        $this->assertTrue(AiGate::isReadyFor($this->container, $project));
        $this->assertTrue(AiGate::isOpenFor($this->container, $project));
    }

    public function testNoBotProfileWithoutAiConnector(): void
    {
        $this->routedProfiles(defaultKey: 'sk-test-fake-key-1');
        $this->container[AiGate::CONNECTOR_OVERRIDE] = false;
        $this->assertSame('', AiGate::botProfile($this->container, 1));
    }

    public function testAnyExceptionClosesTheGate(): void
    {
        // A container without configModel makes ProviderRegistry::isReady() throw.
        $this->assertFalse(AiGate::isReady(new \Pimple\Container(), 80400, true, '1.4.0', true));
    }

    /** p1 (the default, with $defaultKey) and p2 (offered to projects, with a key). */
    private function routedProfiles(string $defaultKey): void
    {
        require_once dirname(__DIR__, 2).'/AiConnector/vendor/autoload.php';
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([
                ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
                ['id' => 'p2', 'label' => 'Opus', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'available' => true],
            ]),
            'aiconnector_default' => 'p1',
            'aiconnector_key_p1' => $defaultKey,
            'aiconnector_key_p2' => 'sk-test-fake-key-2',
        ]);
        $this->container['memoryCache']->flush();
    }

    private function configureProfile(): void
    {
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([
                ['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
            ]),
            'aiconnector_key_p1' => 'sk-test-fake-key-for-gate-test',
        ]);
    }
}
