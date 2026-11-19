<?php
require_once dirname(__DIR__, 3).'/AiConnector/vendor/autoload.php';

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Kanboard\Plugin\AgentChat\Bot\SourceCap;
use PHPUnit\Framework\TestCase;

class SourceCapTest extends TestCase
{
    /** One token per 4 characters, as ModelCatalog counts for most providers. */
    private function cap(int $cap): SourceCap
    {
        return new SourceCap($cap, static fn (string $text): int => (int) ceil(mb_strlen($text, 'UTF-8') / 4));
    }

    private function tool(string $content): ToolInterface
    {
        return new class($content) implements ToolInterface {
            public int $runs = 0;

            public function __construct(private string $content)
            {
            }

            public function name(): string
            {
                return 'lookup';
            }

            public function description(): string
            {
                return 'A lookup.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function execute(array $input): ToolResult
            {
                $this->runs++;
                return ToolResult::success($this->content);
            }

            public function toFunctionSchema(): array
            {
                return ['type' => 'function', 'function' => ['name' => 'lookup', 'description' => 'A lookup.', 'parameters' => []]];
            }
        };
    }

    public function testUnderTheCapResultsGoInWhole(): void
    {
        $tool = $this->tool(str_repeat('a', 300));
        [$wrapped] = $this->cap(200)->wrap([$tool]);

        $this->assertSame('lookup', $wrapped->name());
        $this->assertSame(str_repeat('a', 300), $wrapped->execute([])->content);
        $this->assertSame(str_repeat('a', 300), $wrapped->execute([])->content, '150 tokens in all, under 200');
    }

    public function testTheCrossingResultIsCutToTheRestAsValidJsonThenTheFixedText(): void
    {
        $content = '{"results":["'.str_repeat('é"\\ ', 200).'"]}';
        $tool = $this->tool($content);
        $cap = $this->cap(400);
        [$a, $b] = $cap->wrap([$this->tool(str_repeat('a', 1200)), $tool]);
        $this->assertSame(str_repeat('a', 1200), $a->execute([])->content, '300 of 400 tokens');

        $cut = $b->execute([])->content;
        $this->assertLessThanOrEqual(100, (int) ceil(mb_strlen($cut, 'UTF-8') / 4), 'within the 100 tokens left');
        $this->assertGreaterThan(90, (int) ceil(mb_strlen($cut, 'UTF-8') / 4), 'and using them');
        $data = json_decode($cut, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($data['truncated']);
        $this->assertTrue($data['untrusted']);
        $this->assertNotSame('', $data['partial']);
        $this->assertStringStartsWith($data['partial'], $content, 'a prefix of the result, cut on a character boundary');
        $this->assertTrue(mb_check_encoding($data['partial'], 'UTF-8'));

        $runs = $tool->runs;
        $this->assertSame(SourceCap::TEXT, $b->execute([])->content, 'past the cap, the fixed text');
        $this->assertSame($runs, $tool->runs, 'and the tool is not run');
    }

    public function testTooLittleLeftForAPartialGetsTheFixedText(): void
    {
        $cap = $this->cap(100);
        [$a, $b] = $cap->wrap([$this->tool(str_repeat('a', 396)), $this->tool(str_repeat('b', 400))]);
        $a->execute([]);

        $this->assertSame(SourceCap::TEXT, $b->execute([])->content, '1 token left: no room for a partial');
    }
}
