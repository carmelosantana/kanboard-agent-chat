<?php
require_once dirname(__DIR__, 3).'/AiConnector/vendor/autoload.php';
require_once dirname(__DIR__, 2).'/vendor-chatparts/autoload.php';

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Kanboard\Plugin\AgentChat\Bot\SourceCap;
use Kanboard\Plugin\AgentChat\Bot\SourceRegistry;
use PHPUnit\Framework\TestCase;

class SourceCapTest extends TestCase
{
    /** One token per 4 characters, as ModelCatalog counts for most providers. */
    private function cap(int $cap, ?SourceRegistry $sources = null): SourceCap
    {
        return new SourceCap($cap, static fn (string $text): int => (int) ceil(mb_strlen($text, 'UTF-8') / 4), $sources);
    }

    /** A tool that registers $items task sources and lists them as a tool would: {"results":[{"n":…,"title":…},…]}. */
    private function listing(SourceRegistry $sources, int $from, int $items): ToolInterface
    {
        return new class($sources, $from, $items) implements ToolInterface {
            public function __construct(private SourceRegistry $sources, private int $from, private int $items)
            {
            }

            public function name(): string
            {
                return 'search_tasks';
            }

            public function description(): string
            {
                return 'A listing.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function execute(array $input): ToolResult
            {
                $results = [];
                for ($i = $this->from; $i < $this->from + $this->items; $i++) {
                    $results[] = ['n' => $this->sources->register('task', (string) $i, "Task $i", "/task/$i"), 'title' => str_repeat('t', 200)];
                }
                return ToolResult::success(json_encode(['results' => $results]));
            }

            public function toFunctionSchema(): array
            {
                return ['type' => 'function', 'function' => ['name' => 'search_tasks', 'description' => 'A listing.', 'parameters' => []]];
            }
        };
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

    public function testACutResultKeepsOnlyTheSourcesTheModelSees(): void
    {
        $sources = new SourceRegistry();
        $sources->register('task', '999', 'Known', '/task/999');
        $cap = $this->cap(400, $sources);
        [$a, $b] = $cap->wrap([$this->tool(str_repeat('a', 1000)), $this->listing($sources, 1, 10)]);
        $a->execute([]);

        $cut = json_decode($b->execute([])->content, true)['partial'];

        preg_match_all('/"n":(\d+)[,}]/', $cut, $m);
        $seen = array_map('intval', $m[1]);
        $this->assertNotEmpty($seen);
        $this->assertLessThan(11, max($seen), 'some items were cut off');
        $this->assertSame(range(1, max($seen)), array_column($sources->all(), 'n'), 'the known source and the shown ones stay; the cut-off ones are forgotten');
        $this->assertNull($sources->resolve(11));
        $this->assertSame(max($seen) + 1, $sources->register('task', 'later', 'Later', '/task/later'), 'numbering goes on from there');
    }

    public function testAResultReplacedByTheFixedTextKeepsNoNewSources(): void
    {
        $sources = new SourceRegistry();
        $cap = $this->cap(100, $sources);
        [$a, $b] = $cap->wrap([$this->tool(str_repeat('a', 396)), $this->listing($sources, 1, 5)]);
        $a->execute([]);

        $this->assertSame(SourceCap::TEXT, $b->execute([])->content);
        $this->assertSame([], $sources->all());
    }
}
