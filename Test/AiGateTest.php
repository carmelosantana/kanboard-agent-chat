<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AgentChat\Model\AiGate;

class AiGateTest extends Base
{
    public function testFalseBelowPhp84(): void
    {
        $this->configureProfile();
        $this->assertFalse(AiGate::isReady($this->container, 80399, true, '1.2.0', true));
    }

    public function testFalseWhenConnectorAbsent(): void
    {
        $this->configureProfile();
        $this->assertFalse(AiGate::isReady($this->container, 80400, false, '1.2.0', true));
    }

    public function testFalseWhenConnectorTooOld(): void
    {
        $this->configureProfile();
        $this->assertFalse(AiGate::isReady($this->container, 80400, true, '1.1.6', true));
        $this->assertTrue(AiGate::isReady($this->container, 80400, true, '1.10.0', true));
    }

    public function testFalseWhenPhpAgentsLacksRunLimits(): void
    {
        $this->configureProfile();
        $this->assertFalse(AiGate::isReady($this->container, 80400, true, '1.2.0', false));
    }

    public function testFalseWhenNoProviderConfigured(): void
    {
        $this->assertFalse(AiGate::isReady($this->container, 80400, true, '1.2.0', true));
    }

    public function testTrueWhenEveryBranchHolds(): void
    {
        $this->configureProfile();
        $this->assertTrue(AiGate::isReady($this->container, 80400, true, '1.2.0', true));
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

    public function testAnyExceptionClosesTheGate(): void
    {
        // A container without configModel makes ProviderRegistry::isReady() throw.
        $this->assertFalse(AiGate::isReady(new \Pimple\Container(), 80400, true, '1.2.0', true));
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
