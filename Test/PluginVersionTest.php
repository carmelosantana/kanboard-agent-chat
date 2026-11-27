<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AgentChat\Plugin;

// Tag == plugin.json == getPluginVersion(): drift across the three is the most common release defect.
class PluginVersionTest extends Base
{
    public function testVersionsAgree(): void
    {
        $json = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
        $this->assertSame('0.2.0', $json['version']);
        $this->assertSame($json['version'], (new Plugin($this->container))->getPluginVersion());
        $this->assertSame('AgentChat', $json['name']);
        $this->assertSame('AgentChat', (new Plugin($this->container))->getPluginName());
    }

    // The release repo is carmelosantana/kanboard-agent-chat (spec); ModMenu links the homepage.
    public function testHomepageNamesTheReleaseRepo(): void
    {
        $json = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
        $this->assertSame('https://github.com/carmelosantana/kanboard-agent-chat', $json['homepage']);
        $this->assertSame($json['homepage'], (new Plugin($this->container))->getPluginHomepage());
    }

    // ModMenu reads {plugin, min_version, reason} with a BARE version; any other shape is silently dropped.
    public function testRecommendsUsesModMenuDependencyShape(): void
    {
        $json = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
        $this->assertSame([], $json['requires']);
        $this->assertSame([
            ['plugin' => 'AiConnector', 'min_version' => '1.4.0', 'reason' => 'Project bot answers'],
            ['plugin' => 'Agents', 'min_version' => '0.5.0', 'reason' => 'Agent badges'],
        ], $json['recommends']);
    }

    // The gate's minimum and the declared recommendation are one number.
    public function testGateMinimumMatchesRecommendedAiConnector(): void
    {
        $json = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
        $this->assertSame($json['recommends'][0]['min_version'], \Kanboard\Plugin\AgentChat\Model\AiGate::MIN_CONNECTOR_VERSION);
    }

    public function testInitializeLoadsVendoredChatparts(): void
    {
        // tests/units/Base.php doesn't register ApiProvider; initialize() needs $this->api.
        $this->container->register(new \Kanboard\ServiceProvider\ApiProvider());
        (new Plugin($this->container))->initialize();
        $this->assertTrue(class_exists(\ChatParts\Model\Message::class));
    }
}
