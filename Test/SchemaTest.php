<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class SchemaTest extends Base
{
    private const TABLES = ['agentchat_rooms', 'agentchat_messages', 'agentchat_runs', 'agentchat_files', 'agentchat_knowledge'];

    // Every UNIQUE is a named index with the same name in all three dialects, so a later
    // version can drop it with one statement (an inline SQLite UNIQUE can only go by a table rebuild).
    private const UNIQUE_INDEXES = [
        'agentchat_rooms_project_uidx' => 'agentchat_rooms',
        'agentchat_messages_client_msg_uidx' => 'agentchat_messages',
        'agentchat_runs_stream_token_uidx' => 'agentchat_runs',
        'agentchat_knowledge_source_uidx' => 'agentchat_knowledge',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        $this->container['db']->table('projects')->insert(['name' => 'Chat', 'is_active' => 1]);
    }

    public function testVersion1CreatesAllTablesInThisDialect(): void
    {
        $db = $this->container['db'];
        $now = time();

        $roomId = $db->table('agentchat_rooms')->persist(['kind' => 'project', 'project_id' => 1, 'name' => 'Chat', 'created_at' => $now]);
        $this->assertGreaterThan(0, (int) $roomId);

        $msg = ['room_id' => $roomId, 'sender_kind' => 'human', 'sender_id' => 1, 'parts' => '[]', 'body_text' => 'hi',
            'client_msg_id' => 'c-1', 'created_at' => $now, 'updated_at' => $now];
        $msgId = $db->table('agentchat_messages')->persist($msg);
        $this->assertGreaterThan(0, (int) $msgId);
        $row = $db->table('agentchat_messages')->eq('id', $msgId)->findOne();
        $this->assertSame('room', $row['visibility']);
        $this->assertNull($row['private_to']);
        $this->assertNull($row['task_id']);

        // Idempotent send: the same client_msg_id from the same sender in the same room is rejected.
        $this->assertFalse($db->table('agentchat_messages')->insert($msg));
        // ...but another sender may reuse it.
        $this->assertTrue($db->table('agentchat_messages')->insert(['sender_id' => 2] + $msg));

        $runId = $db->table('agentchat_runs')->persist(['message_id' => $msgId, 'room_id' => $roomId, 'asked_by' => 1,
            'stream_token_hash' => str_repeat('a', 64), 'usage' => '{}']);
        $run = $db->table('agentchat_runs')->eq('id', $runId)->findOne();
        $this->assertSame('pending', $run['status']);
        $this->assertSame(0, (int) $run['stop_requested']);

        $this->assertGreaterThan(0, (int) $db->table('agentchat_files')->persist(['message_id' => $msgId, 'room_id' => $roomId,
            'uploaded_by' => 1, 'name' => 'a.png', 'mime' => 'image/png', 'size' => 10, 'storage_key' => 'agentchat/x', 'created_at' => $now]));

        $k = ['project_id' => 1, 'source' => 'save-it', 'external_id' => 'e1', 'title' => 'T', 'body' => 'B', 'created_at' => $now, 'updated_at' => $now];
        $this->assertGreaterThan(0, (int) $db->table('agentchat_knowledge')->persist($k));
        $this->assertFalse($db->table('agentchat_knowledge')->insert($k), 'UNIQUE(project_id, source, external_id)');
        // Ids are opaque: matching is exact in every dialect (MySQL's default collation would fold case).
        $this->assertTrue($db->table('agentchat_knowledge')->insert(['external_id' => 'E1'] + $k));
        $this->assertTrue($db->table('agentchat_messages')->insert(['client_msg_id' => 'C-1'] + $msg));

        foreach (self::TABLES as $t) {
            $this->assertGreaterThan(0, $db->table($t)->count(), $t);
        }

        // Deleting the project removes its room (and messages, runs, files) and its knowledge.
        $db->table('projects')->eq('id', 1)->remove();
        foreach (self::TABLES as $t) {
            $this->assertSame(0, $db->table($t)->count(), $t);
        }
    }

    public function testKnowledgeFtsTriggersIndexInsertUpdateDelete(): void
    {
        $db = $this->container['db'];
        $now = time();
        $id = $db->table('agentchat_knowledge')->persist(['project_id' => 1, 'source' => 'save-it', 'external_id' => 'e1',
            'title' => 'Homepage', 'body' => 'The hero autoplay is off', 'created_at' => $now, 'updated_at' => $now]);

        $this->assertSame([(int) $id], $this->search('hero autoplay'));

        $db->table('agentchat_knowledge')->eq('id', $id)->update(['body' => 'The carousel loops', 'updated_at' => $now + 1]);
        $this->assertSame([], $this->search('autoplay'));
        $this->assertSame([(int) $id], $this->search('carousel'));

        $db->table('agentchat_knowledge')->eq('id', $id)->remove();
        $this->assertSame([], $this->search('carousel'));
    }

    public function testRunningVersion1TwiceIsANoOp(): void
    {
        $db = $this->container['db'];
        $roomId = $db->table('agentchat_rooms')->persist(['kind' => 'project', 'project_id' => 1, 'name' => 'Chat', 'created_at' => 1]);

        \Kanboard\Plugin\AgentChat\Schema\version_1($db->getConnection());

        $this->assertSame(1, $db->table('agentchat_rooms')->count());
        $this->assertSame('Chat', $db->table('agentchat_rooms')->eq('id', $roomId)->findOneColumn('name'));
    }

    public function testOneProjectRoomPerProjectButOtherKindsAreFree(): void
    {
        $db = $this->container['db'];
        $room = ['kind' => 'project', 'project_id' => 1, 'name' => 'Chat', 'created_at' => 1];
        $this->assertTrue($db->table('agentchat_rooms')->insert($room));
        $this->assertFalse($db->table('agentchat_rooms')->insert($room));
        // Rooms without a project (v2 team, dm) never collide.
        $this->assertTrue($db->table('agentchat_rooms')->insert(['kind' => 'team', 'project_id' => null] + $room));
        $this->assertTrue($db->table('agentchat_rooms')->insert(['kind' => 'team', 'project_id' => null] + $room));
        // The rule binds only kind = 'project' (partial index); MySQL has no partial index, so there
        // (kind, project_id) is unique for every kind.
        $this->assertTrue($db->table('agentchat_rooms')->insert(['kind' => 'team'] + $room));
        $this->assertSame(DB_DRIVER !== 'mysql', $db->table('agentchat_rooms')->insert(['kind' => 'team'] + $room));
    }

    public function testUniqueKeysAreNamedIndexesALaterVersionCanDrop(): void
    {
        $pdo = $this->container['db']->getConnection();
        foreach (self::UNIQUE_INDEXES as $index => $table) {
            $pdo->exec(DB_DRIVER === 'mysql' ? "DROP INDEX $index ON $table" : "DROP INDEX $index");
        }
        // With the keys gone, duplicates go in: nothing else was enforcing them.
        $db = $this->container['db'];
        $room = ['kind' => 'project', 'project_id' => 1, 'name' => 'Chat', 'created_at' => 1];
        $this->assertTrue($db->table('agentchat_rooms')->insert($room));
        $this->assertTrue($db->table('agentchat_rooms')->insert($room));
    }

    public function testAllThreeDialectsDeclareVersion1(): void
    {
        foreach (['Sqlite', 'Mysql', 'Postgres'] as $d) {
            $file = __DIR__."/../Schema/$d.php";
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $out, $code);
            $this->assertSame(0, $code, "$d: ".implode("\n", $out));
            $src = file_get_contents($file);
            foreach (array_keys(self::UNIQUE_INDEXES) as $index) {
                $this->assertStringContainsString($index, $src, "$d: $index");
            }
            $this->assertStringContainsString('const VERSION = 1;', $src, $d);
            $this->assertStringContainsString('function version_1', $src, $d);
            foreach (self::TABLES as $t) {
                $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS $t ", $src, "$d: $t");
            }
        }
        $this->assertStringContainsString('USING fts5', file_get_contents(__DIR__.'/../Schema/Sqlite.php'));
        $this->assertStringContainsString('FULLTEXT KEY ft_title_body (title, body)', file_get_contents(__DIR__.'/../Schema/Mysql.php'));
        $pg = file_get_contents(__DIR__.'/../Schema/Postgres.php');
        $this->assertStringContainsString('search tsvector GENERATED ALWAYS AS', $pg);
        $this->assertStringContainsString('USING GIN (search)', $pg);
    }

    /** Knowledge ids matching a full-text query, in the current dialect. */
    private function search(string $q): array
    {
        $pdo = $this->container['db']->getConnection();
        $sql = match (DB_DRIVER) {
            'sqlite' => 'SELECT rowid FROM agentchat_knowledge_fts WHERE agentchat_knowledge_fts MATCH ? ORDER BY rowid',
            'mysql' => 'SELECT id FROM agentchat_knowledge WHERE MATCH(title, body) AGAINST (? IN BOOLEAN MODE) ORDER BY id',
            'postgres' => "SELECT id FROM agentchat_knowledge WHERE search @@ plainto_tsquery('simple', ?) ORDER BY id",
        };
        if (DB_DRIVER === 'mysql') {
            $q = implode(' ', array_map(fn ($w) => '+'.$w, explode(' ', $q)));
        }
        $st = $pdo->prepare($sql);
        $st->execute([$q]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }
}
