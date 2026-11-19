<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AgentChat\Bot\Instructions;

class InstructionsTest extends Base
{
    public function testMarksRetrievedContentUntrusted(): void
    {
        $text = Instructions::build(['id' => 7, 'name' => 'Website'], ['name' => 'Ana Lima', 'username' => 'ana', 'role' => 'Project Member'], null);

        $this->assertStringContainsString('Website', $text);
        $this->assertStringContainsString('Ana Lima', $text);
        $this->assertStringContainsString('Project Member', $text);
        $this->assertMatchesRegularExpression('/untrusted data/i', $text);
        $this->assertMatchesRegularExpression('/never instructions/i', $text);
        $this->assertStringContainsString('[n]', $text);
        $this->assertMatchesRegularExpression('/only.*tool results/i', $text);
        $this->assertMatchesRegularExpression('/language/i', $text);
        $this->assertMatchesRegularExpression('/nothing relevant/i', $text);
        $this->assertStringNotContainsString('Task #', $text, 'no task outside a drawer');
    }

    public function testDrawerNamesTheTaskAndFallsBackToUsername(): void
    {
        $text = Instructions::build(['id' => 7, 'name' => 'Website'], ['name' => '', 'username' => 'ana'], ['id' => 42, 'title' => 'Fix login']);

        $this->assertStringContainsString('ana', $text);
        $this->assertStringContainsString('Task #42', $text);
        $this->assertStringContainsString('Fix login', $text);
    }
}
