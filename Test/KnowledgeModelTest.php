<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AgentChat\Model\KnowledgeModel;

class KnowledgeModelTest extends Base
{
    private int $projectA;
    private int $projectB;
    private KnowledgeModel $knowledge;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        $this->projectA = (int) $this->container['projectModel']->create(['name' => 'Archive']);
        $this->projectB = (int) $this->container['projectModel']->create(['name' => 'Other']);
        $this->knowledge = new KnowledgeModel($this->container);
    }

    private function doc(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Interview with the founder',
            'body' => 'We talked about the autoplay hero video on the landing page and why it converts.',
            'url' => 'https://www.instagram.com/p/abc123/',
            'author' => 'founder',
            'occurred_at' => 1763500000,
            'metadata' => ['likes' => 12],
        ];
    }

    private function rowCount(): int
    {
        return (int) $this->container['db']->table(KnowledgeModel::TABLE)->count();
    }

    private function assertInvalid(callable $fn, string $why): void
    {
        try {
            $fn();
            $this->fail("Expected InvalidArgumentException: $why");
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertFtsIntact(): void
    {
        if (DB_DRIVER === 'sqlite') {
            // Throws "database disk image is malformed" if the external-content index has drifted.
            $this->container['db']->getConnection()->exec("INSERT INTO agentchat_knowledge_fts(agentchat_knowledge_fts, rank) VALUES ('integrity-check', 1)");
        }
        $this->addToAssertionCount(1);
    }

    public function testUpsertIsIdempotent(): void
    {
        $id = $this->knowledge->upsert($this->projectA, 'save-it', 'post-1', $this->doc());
        $first = $this->knowledge->getById($id);
        $again = $this->knowledge->upsert($this->projectA, 'save-it', 'post-1', $this->doc(['body' => 'A completely new transcript about pricing tiers.']));

        $this->assertSame($id, $again);
        $this->assertSame(1, $this->rowCount());
        $row = $this->knowledge->getById($id);
        $this->assertSame('A completely new transcript about pricing tiers.', $row['body']);
        $this->assertSame((int) $first['created_at'], (int) $row['created_at']);
        $this->assertSame(['likes' => 12], json_decode($row['metadata'], true));

        // The same external id under another source or project is another document.
        $this->assertNotSame($id, $this->knowledge->upsert($this->projectA, 'other-agent', 'post-1', $this->doc()));
        $this->assertNotSame($id, $this->knowledge->upsert($this->projectB, 'save-it', 'post-1', $this->doc()));
        $this->assertSame(3, $this->rowCount());
    }

    public function testFtsStaysConsistentAfterUpdateAndDelete(): void
    {
        $this->knowledge->upsert($this->projectA, 'save-it', 'post-1', $this->doc());
        $this->assertCount(1, $this->knowledge->search($this->projectA, 'autoplay'));

        $this->knowledge->upsert($this->projectA, 'save-it', 'post-1', $this->doc(['body' => 'Now it is about pricing tiers.']));
        $this->assertSame([], $this->knowledge->search($this->projectA, 'autoplay'));
        $this->assertCount(1, $this->knowledge->search($this->projectA, 'pricing'));
        $this->assertFtsIntact();

        $this->assertTrue($this->knowledge->delete($this->projectA, 'save-it', 'post-1'));
        $this->assertFalse($this->knowledge->delete($this->projectA, 'save-it', 'post-1'));
        $this->assertSame([], $this->knowledge->search($this->projectA, 'pricing'));
        $this->assertSame(0, $this->rowCount());
        $this->assertFtsIntact();
    }

    public function testDeleteIsScopedToItsKey(): void
    {
        $this->knowledge->upsert($this->projectA, 'save-it', 'post-1', $this->doc());
        $this->assertFalse($this->knowledge->delete($this->projectB, 'save-it', 'post-1'));
        $this->assertFalse($this->knowledge->delete($this->projectA, 'other', 'post-1'));
        $this->assertSame(1, $this->rowCount());
    }

    public function testLostInsertRaceFallsBackToUpdate(): void
    {
        $id = $this->knowledge->upsert($this->projectA, 'save-it', 'post-1', $this->doc());
        // A model whose first lookup misses, as when another request inserts between lookup and insert.
        $racing = new class($this->container) extends KnowledgeModel {
            private bool $missed = false;
            protected function findId(int $projectId, string $source, string $externalId): ?int
            {
                if (! $this->missed) {
                    $this->missed = true;
                    return null;
                }
                return parent::findId($projectId, $source, $externalId);
            }
        };
        $this->assertSame($id, $racing->upsert($this->projectA, 'save-it', 'post-1', $this->doc(['body' => 'Raced pricing body.'])));
        $this->assertSame(1, $this->rowCount());
        $this->assertSame('Raced pricing body.', $this->knowledge->getById($id)['body']);
        $this->assertCount(1, $this->knowledge->search($this->projectA, 'raced'));
        $this->assertFtsIntact();
    }

    public function testSearchFindsByBodyWords(): void
    {
        $id = $this->knowledge->upsert($this->projectA, 'save-it', 'post-1', $this->doc());
        $this->knowledge->upsert($this->projectA, 'save-it', 'post-2', $this->doc(['title' => 'Packaging notes', 'body' => 'Recyclable boxes ship next quarter.']));

        $rows = $this->knowledge->search($this->projectA, 'autoplay landing');
        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]['id']);
        $this->assertSame('Interview with the founder', $rows[0]['title']);
        $this->assertSame('https://www.instagram.com/p/abc123/', $rows[0]['url']);
        $this->assertSame(1763500000, $rows[0]['occurred_at']);
        $this->assertSame('save-it', $rows[0]['source']);
        $this->assertStringContainsString('autoplay hero', $rows[0]['snippet']);
        $this->assertSame(['id', 'title', 'url', 'snippet', 'occurred_at', 'source'], array_keys($rows[0]));

        // Titles are searched too.
        $this->assertCount(1, $this->knowledge->search($this->projectA, 'packaging'));
    }

    public function testSearchScopedToProject(): void
    {
        $this->knowledge->upsert($this->projectA, 'save-it', 'post-1', $this->doc());
        $this->knowledge->upsert($this->projectB, 'save-it', 'post-1', $this->doc(['title' => 'Secret autoplay plans']));
        $this->knowledge->upsert($this->projectA, 'other-agent', 'call-9', $this->doc(['title' => 'Call about autoplay']));

        $rows = $this->knowledge->search($this->projectA, 'autoplay');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNotSame('Secret autoplay plans', $row['title']);
        }
        $bySource = $this->knowledge->search($this->projectA, 'autoplay', 'other-agent');
        $this->assertCount(1, $bySource);
        $this->assertSame('Call about autoplay', $bySource[0]['title']);
        $this->assertSame([], $this->knowledge->search($this->projectA, 'autoplay', 'nobody'));
        $this->assertSame([], $this->knowledge->search(999, 'autoplay'));
    }

    public function testSearchLimitIsClamped(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->knowledge->upsert($this->projectA, 'save-it', "post-$i", $this->doc());
        }
        $this->assertCount(8, $this->knowledge->search($this->projectA, 'autoplay'));
        $this->assertCount(20, $this->knowledge->search($this->projectA, 'autoplay', null, 500));
        $this->assertCount(1, $this->knowledge->search($this->projectA, 'autoplay', null, 0));
        $this->assertCount(1, $this->knowledge->search($this->projectA, 'autoplay', null, -3));
    }

    public function testSnippetIsShortPlainText(): void
    {
        $body = str_repeat("Lorem ipsum dolor sit amet.\n\n", 300).'The <b>zeppelin</b> idea came up late. '.str_repeat('Filler words here. ', 300);
        $this->knowledge->upsert($this->projectA, 'save-it', 'long', $this->doc(['body' => $body]));
        $snippet = $this->knowledge->search($this->projectA, 'zeppelin')[0]['snippet'];
        $this->assertLessThanOrEqual(300, mb_strlen($snippet));
        $this->assertStringContainsString('zeppelin', $snippet);
        $this->assertStringNotContainsString("\n", $snippet);
    }

    public function testFtsQuerySanitised(): void
    {
        $this->knowledge->upsert($this->projectA, 'save-it', 'post-1', $this->doc(['body' => 'The hero shot of the product.']));

        $this->assertIsArray($this->knowledge->search($this->projectA, '"hero" OR *'));
        $this->assertSame([], $this->knowledge->search($this->projectA, '*** ""  -- () :'));
        $this->assertSame([], $this->knowledge->search($this->projectA, '   '));
        $this->assertCount(1, $this->knowledge->search($this->projectA, 'hero'));
        $this->assertCount(1, $this->knowledge->search($this->projectA, 'hero"'));
        $this->assertIsArray($this->knowledge->search($this->projectA, 'NEAR(hero product)'));
        $this->assertIsArray($this->knowledge->search($this->projectA, 'body:hero AND ^title'));
        if (DB_DRIVER === 'sqlite') {
            // FTS5 syntax is taken as plain words, any of which may match (ranked by bm25).
            $this->assertCount(1, $this->knowledge->search($this->projectA, 'NEAR(hero product)'));
            $this->assertCount(1, $this->knowledge->search($this->projectA, '"hero" OR *'));
        }
        // Over 200 characters is refused, not truncated into a different question.
        $this->assertSame([], $this->knowledge->search($this->projectA, str_repeat('hero ', 41)));
    }

    public function testLimits(): void
    {
        $this->assertInvalid(fn () => $this->knowledge->upsert($this->projectA, 's', 'x', $this->doc(['body' => str_repeat('a', 200001)])), 'body over 200 000 chars');
        $this->knowledge->upsert($this->projectA, 's', 'x', $this->doc(['body' => str_repeat('é', 200000)]));

        $meta = ['k' => str_repeat('a', 16384 - 8)]; // {"k":"…"} is 8 bytes of overhead
        $this->assertSame(16384, strlen(json_encode($meta)));
        $this->knowledge->upsert($this->projectA, 's', 'y', $this->doc(['metadata' => $meta]));
        $meta['k'] .= 'a';
        $this->assertInvalid(fn () => $this->knowledge->upsert($this->projectA, 's', 'z', $this->doc(['metadata' => $meta])), 'metadata over 16 KB');
        $this->assertSame(2, $this->rowCount());
    }

    public function testValidation(): void
    {
        $k = $this->knowledge;
        $p = $this->projectA;
        $cases = [
            'project 0' => fn () => $k->upsert(0, 's', 'x', $this->doc()),
            'empty source' => fn () => $k->upsert($p, '', 'x', $this->doc()),
            'long source' => fn () => $k->upsert($p, str_repeat('s', 65), 'x', $this->doc()),
            'control in source' => fn () => $k->upsert($p, "save\nit", 'x', $this->doc()),
            'empty external id' => fn () => $k->upsert($p, 's', '', $this->doc()),
            'long external id' => fn () => $k->upsert($p, 's', str_repeat('x', 192), $this->doc()),
            'NUL in external id' => fn () => $k->upsert($p, 's', "x\0", $this->doc()),
            'bad utf-8 external id' => fn () => $k->upsert($p, 's', "x\xff", $this->doc()),
            'missing title' => fn () => $k->upsert($p, 's', 'x', array_diff_key($this->doc(), ['title' => 1])),
            'blank title' => fn () => $k->upsert($p, 's', 'x', $this->doc(['title' => '  '])),
            'long title' => fn () => $k->upsert($p, 's', 'x', $this->doc(['title' => str_repeat('t', 256)])),
            'control in title' => fn () => $k->upsert($p, 's', 'x', $this->doc(['title' => "a\x07b"])),
            'NUL in body' => fn () => $k->upsert($p, 's', 'x', $this->doc(['body' => "a\0b"])),
            'bad utf-8 body' => fn () => $k->upsert($p, 's', 'x', $this->doc(['body' => "a\xc3"])),
            'non-string body' => fn () => $k->upsert($p, 's', 'x', $this->doc(['body' => ['a']])),
            'javascript url' => fn () => $k->upsert($p, 's', 'x', $this->doc(['url' => 'javascript:alert(1)'])),
            'data url' => fn () => $k->upsert($p, 's', 'x', $this->doc(['url' => 'data:text/html,hi'])),
            'relative url' => fn () => $k->upsert($p, 's', 'x', $this->doc(['url' => '/p/abc'])),
            'url with space' => fn () => $k->upsert($p, 's', 'x', $this->doc(['url' => 'https://a.example/ b'])),
            'long url' => fn () => $k->upsert($p, 's', 'x', $this->doc(['url' => 'https://a.example/'.str_repeat('a', 2048)])),
            'long author' => fn () => $k->upsert($p, 's', 'x', $this->doc(['author' => str_repeat('a', 256)])),
            'negative occurred_at' => fn () => $k->upsert($p, 's', 'x', $this->doc(['occurred_at' => -1])),
            'string occurred_at' => fn () => $k->upsert($p, 's', 'x', $this->doc(['occurred_at' => 'yesterday'])),
            'scalar metadata' => fn () => $k->upsert($p, 's', 'x', $this->doc(['metadata' => 'x'])),
            'unknown field' => fn () => $k->upsert($p, 's', 'x', $this->doc(['color' => 'red'])),
            'delete empty source' => fn () => $k->delete($p, '', 'x'),
        ];
        foreach ($cases as $why => $fn) {
            $this->assertInvalid($fn, $why);
        }
        $this->assertSame(0, $this->rowCount());

        // Optional fields may be left out or empty.
        $id = $k->upsert($p, 's', 'x', ['title' => 'Only a title', 'body' => '']);
        $row = $k->getById($id);
        $this->assertSame('', (string) $row['url']);
        $this->assertSame('http://a.example/x?y=1', $k->getById($k->upsert($p, 's', 'y', $this->doc(['url' => 'http://a.example/x?y=1'])))['url']);
        $this->assertNull($k->getById(999999));
    }
}
