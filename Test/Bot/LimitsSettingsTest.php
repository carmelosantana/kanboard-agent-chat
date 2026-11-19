<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AgentChat\Model\LimitsSettings;

class LimitsSettingsTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/AiConnector/vendor/autoload.php';
    }

    public function testDefaults(): void
    {
        $limits = new LimitsSettings($this->container);

        $this->assertSame(6, $limits->maxIterations());
        $this->assertSame(8, $limits->maxToolCallsPerRun());
        $this->assertSame(4, $limits->maxToolCallsPerTurn());
        $this->assertSame(90.0, $limits->maxRunSeconds());
        $this->assertSame(262144, $limits->maxResponseBytes());
        $this->assertSame(1, $limits->maxConcurrentPerUser());
        $this->assertSame(4, $limits->maxConcurrentInstance());
        $this->assertSame(30, $limits->questionsPerHour());

        $run = $limits->runLimits(5000);
        $this->assertSame([4, 8, 90.0, 262144, 5000], [$run->maxToolCallsPerTurn, $run->maxToolCallsPerRun, $run->maxRunSeconds, $run->maxResponseBytes, $run->maxInputTokens]);
        $this->assertFalse($run->executeUnadvertisedTools);
    }

    public function testConfigOverridesAndInvalidValuesFallBack(): void
    {
        $this->container['configModel']->save([
            'agentchat_max_iterations' => '3',
            'agentchat_max_run_seconds' => '30.5',
            'agentchat_questions_per_hour' => '0',
            'agentchat_max_tool_calls_per_run' => '-2',
            'agentchat_max_response_bytes' => 'lots',
        ]);
        $this->container['memoryCache']->flush();
        $limits = new LimitsSettings($this->container);

        $this->assertSame(3, $limits->maxIterations());
        $this->assertSame(30.5, $limits->maxRunSeconds());
        $this->assertSame(30, $limits->questionsPerHour(), 'zero would mean unlimited: never');
        $this->assertSame(8, $limits->maxToolCallsPerRun());
        $this->assertSame(262144, $limits->maxResponseBytes());
    }
}
