<?php

use ChatParts\Model\Part\SourcePart;
use Kanboard\Plugin\AgentChat\Bot\SourceRegistry;
use PHPUnit\Framework\TestCase;

class SourceRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__.'/../../vendor-chatparts/autoload.php';
    }

    public function testSameRefSameNumber(): void
    {
        $sources = new SourceRegistry();

        $this->assertSame(1, $sources->register('task', '12', 'Fix login', '/task/12'));
        $this->assertSame(2, $sources->register('knowledge', '12', 'Founder interview', '/agentchat/knowledge/12'));
        $this->assertSame(1, $sources->register('task', '12', 'Fix login (renamed)', '/task/12'));
        $this->assertSame(3, $sources->register('comment', '7', 'Comment on Fix login', '/task/12#comment-7'));

        $all = $sources->all();
        $this->assertCount(3, $all);
        $this->assertContainsOnlyInstancesOf(SourcePart::class, $all);
        $this->assertSame([1, 2, 3], array_map(static fn (SourcePart $s): int => $s->n, $all));
        $this->assertSame('Fix login', $all[0]->title, 'the first registration wins');
        $this->assertSame(['task', '12', '/task/12'], [$all[0]->kind, $all[0]->ref, $all[0]->url]);
        $this->assertCount(3, array_unique(array_map(static fn (SourcePart $s): string => $s->id, $all)), 'part ids are unique');
    }

    public function testResolve(): void
    {
        $sources = new SourceRegistry();
        $sources->register('task', '5', 'Five', '/task/5');

        $this->assertSame('Five', $sources->resolve(1)?->title);
        $this->assertNull($sources->resolve(0));
        $this->assertNull($sources->resolve(2));
        $this->assertNull($sources->resolve(-1));
    }

    public function testCappedAtNinetyNine(): void
    {
        $sources = new SourceRegistry();
        for ($i = 1; $i <= 99; $i++) {
            $this->assertSame($i, $sources->register('task', (string) $i, "Task $i", "/task/$i"));
        }

        $this->assertNull($sources->register('task', '100', 'Task 100', '/task/100'), 'no number past 99');
        $this->assertSame(42, $sources->register('task', '42', 'Task 42', '/task/42'), 'known refs keep their number');
        $this->assertCount(99, $sources->all());
        $this->assertNull($sources->resolve(100));
    }

    public function testRefusesUnknownKindAndOffSiteUrls(): void
    {
        $sources = new SourceRegistry();
        foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)', 'task/5', '/task/5 x', ''] as $url) {
            try {
                $sources->register('task', '5', 'Five', $url);
                $this->fail("accepted $url");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $sources->register('file', '5', 'Five', '/task/5');
    }
}
