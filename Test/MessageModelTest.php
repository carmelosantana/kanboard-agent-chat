<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use ChatParts\Model\Message;
use ChatParts\Model\MessageStatus;
use ChatParts\Model\Participant;
use ChatParts\Model\Part\SourcePart;
use ChatParts\Model\Part\TextPart;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use PHPUnit\Framework\Attributes\DataProvider;

class MessageModelTest extends Base
{
    private int $roomId;
    private MessageModel $messages;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../vendor-chatparts/autoload.php';
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->container['userModel']->create(['username' => 'alice', 'name' => 'Alice A']);
        $this->container['userModel']->create(['username' => 'bob']);
        $this->roomId = (int) (new RoomModel($this->container))->forProject($projectId)['id'];
        $this->messages = new MessageModel($this->container);
    }

    private function human(int $userId): Participant
    {
        return new Participant('human', (string) $userId, 'x');
    }

    private function bot(): Participant
    {
        return new Participant('bot', 'project', 'Project bot');
    }

    private function text(string $s): array
    {
        return [new TextPart('t1', $s)];
    }

    public function testBodyLimitsCountCharacters(): void
    {
        $this->assertNull(MessageModel::validateBody(str_repeat('😀', 8000)));
        $this->assertNotNull(MessageModel::validateBody(str_repeat('😀', 8001)));
        $this->assertNotNull(MessageModel::validateBody("   \n"));
        $this->assertNotNull(MessageModel::validateBody(''));
        $this->assertNull(MessageModel::validateBody('  a  '));
        // Surrounding whitespace does not count toward the limit.
        $this->assertNull(MessageModel::validateBody(' '.str_repeat('a', 8000)."\n"));
    }

    public function testBodyMustBeCleanUtf8WithoutNul(): void
    {
        $this->assertNotNull(MessageModel::validateBody("\xff"));
        $this->assertNotNull(MessageModel::validateBody("hi \xc3"));
        $this->assertNotNull(MessageModel::validateBody("a\0b"));
    }

    public function testBodyOfOnlyUnicodeWhitespaceIsEmpty(): void
    {
        foreach (["\u{00A0}", "\u{3000}", "\u{200B}", "\u{FEFF}", " \u{2003}\n\u{00A0} "] as $blank) {
            $this->assertNotNull(MessageModel::validateBody($blank), json_encode($blank));
        }
        // Unicode padding does not count toward the limit either.
        $this->assertNull(MessageModel::validateBody("\u{3000}".str_repeat('a', 8000)."\u{00A0}"));
        $this->assertNull(MessageModel::validateBody("\u{00A0}a\u{00A0}"));
    }

    public function testBadBytesAreInvalidArgumentsNeverJsonOrSqlErrors(): void
    {
        $cases = [
            'human invalid utf-8' => fn () => $this->messages->create($this->roomId, $this->human(2), $this->text("\xff")),
            'human NUL' => fn () => $this->messages->create($this->roomId, $this->human(2), $this->text("a\0b")),
            'bot invalid utf-8 part' => fn () => $this->messages->create($this->roomId, $this->bot(), $this->text("\xff")),
            'invalid utf-8 metadata' => fn () => $this->messages->create($this->roomId, $this->bot(), $this->text('ok'), ['metadata' => ['k' => "\xff"]]),
            'update invalid utf-8 metadata' => fn () => $this->messages->update(
                $this->messages->create($this->roomId, $this->bot(), []), ['metadata' => ['k' => "\xff"]]
            ),
        ];
        foreach ($cases as $name => $call) {
            try {
                $call();
                $this->fail("$name accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage(), $name);
            }
        }
    }

    public function testBotTextNulNeverReachesBodyText(): void
    {
        $id = $this->messages->create($this->roomId, $this->bot(), $this->text("a\0b"));
        $this->assertSame('ab', $this->messages->getById($id)['body_text']);
        $this->messages->update($id, ['parts' => $this->text("c\0d")]);
        $this->assertSame('cd', $this->messages->getById($id)['body_text']);
    }

    public function testHumanPrivateMessageMustBeAddressedToItsSender(): void
    {
        $this->assertGreaterThan(0, $this->messages->create($this->roomId, $this->human(2), $this->text('q'), ['visibility' => 'private', 'private_to' => 2, 'task_id' => 5]));
        // The bot answers into the asker's thread.
        $this->assertGreaterThan(0, $this->messages->create($this->roomId, $this->bot(), $this->text('a'), ['visibility' => 'private', 'private_to' => 2, 'task_id' => 5]));

        $this->expectException(InvalidArgumentException::class);
        $this->messages->create($this->roomId, $this->human(2), $this->text('q'), ['visibility' => 'private', 'private_to' => 3, 'task_id' => 5]);
    }

    public function testCreateRejectsAnInvalidHumanBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->messages->create($this->roomId, $this->human(2), $this->text("  \n"));
    }

    public function testBotDraftMayStartEmpty(): void
    {
        $id = $this->messages->create($this->roomId, $this->bot(), [], ['status' => 'submitted']);
        $row = $this->messages->getById($id);
        $this->assertSame('bot', $row['sender_kind']);
        $this->assertNull($row['sender_id']);
        $this->assertSame('submitted', $row['status']);
        $this->assertSame('[]', $row['parts']);
    }

    public function testCreateStoresPartsAndDerivedText(): void
    {
        $parts = [new TextPart('a', 'Hello'), new SourcePart('s', 1, 'Task #1', '/task/1', 'task', '1'), new TextPart('b', 'world')];
        $id = $this->messages->create($this->roomId, $this->human(2), $parts, ['task_id' => 7, 'metadata' => ['asked_by' => 2]]);
        $row = $this->messages->getById($id);

        $this->assertSame('human', $row['sender_kind']);
        $this->assertSame(2, (int) $row['sender_id']);
        $this->assertSame('room', $row['visibility']);
        $this->assertSame('done', $row['status']);
        $this->assertSame(7, (int) $row['task_id']);
        $this->assertSame("Hello\n\nworld", $row['body_text']);
        $this->assertSame(array_map(fn ($p) => $p->toArray(), $parts), json_decode($row['parts'], true));
        $this->assertSame(['asked_by' => 2], json_decode($row['metadata'], true));
    }

    public function testClientMsgIdIsIdempotent(): void
    {
        $first = $this->messages->create($this->roomId, $this->human(2), $this->text('one'), ['client_msg_id' => 'c-1']);
        $again = $this->messages->create($this->roomId, $this->human(2), $this->text('changed'), ['client_msg_id' => 'c-1']);

        $this->assertSame($first, $again);
        $this->assertSame('one', $this->messages->getById($first)['body_text']);
        $this->assertSame(1, $this->container['db']->table('agentchat_messages')->count());

        // Another sender may reuse the id; so may the bot (NULL sender_id), idempotently too.
        $this->assertNotSame($first, $this->messages->create($this->roomId, $this->human(3), $this->text('x'), ['client_msg_id' => 'c-1']));
        $bot = $this->messages->create($this->roomId, $this->bot(), $this->text('b'), ['client_msg_id' => 'c-1']);
        $this->assertSame($bot, $this->messages->create($this->roomId, $this->bot(), $this->text('b2'), ['client_msg_id' => 'c-1']));
        $this->assertSame(3, $this->container['db']->table('agentchat_messages')->count());
    }

    /** @dataProvider invalidOptions */
    #[DataProvider('invalidOptions')]
    public function testCreateRejectsInvalidOptions(array $opts): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->messages->create($this->roomId, $this->human(2), $this->text('hi'), $opts);
    }

    public static function invalidOptions(): array
    {
        return [
            'client_msg_id too long' => [['client_msg_id' => str_repeat('a', 65)]],
            'client_msg_id bad chars' => [['client_msg_id' => 'a b']],
            'client_msg_id empty' => [['client_msg_id' => '']],
            'visibility unknown' => [['visibility' => 'public']],
            'status unknown' => [['status' => 'queued']],
            'private without owner' => [['visibility' => 'private']],
            'room with private_to' => [['private_to' => 2]],
            'unknown option' => [['sender_kind' => 'bot']],
            'reply_to not a positive id' => [['reply_to' => 0]],
        ];
    }

    public function testCreateRejectsAnInvalidSenderOrParts(): void
    {
        foreach ([
            fn () => $this->messages->create($this->roomId, new Participant('robot', '2', 'x'), $this->text('hi')),
            fn () => $this->messages->create($this->roomId, new Participant('human', 'abc', 'x'), $this->text('hi')),
            fn () => $this->messages->create($this->roomId, new Participant('human', null, 'x'), $this->text('hi')),
            fn () => $this->messages->create($this->roomId, $this->human(2), ['not a part']),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("case $i accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testListAfterExcludesPrivate(): void
    {
        $a = $this->messages->create($this->roomId, $this->human(2), $this->text('a'));
        $this->messages->create($this->roomId, $this->human(2), $this->text('secret'), ['visibility' => 'private', 'private_to' => 2, 'task_id' => 5]);
        $b = $this->messages->create($this->roomId, $this->bot(), $this->text('b'));
        $c = $this->messages->create($this->roomId, $this->human(3), $this->text('c'));

        // Not even the owner sees their private thread in the room feed.
        $this->assertSame([$a, $b, $c], array_map('intval', array_column($this->messages->listAfter($this->roomId, 2, 0), 'id')));
        $this->assertSame([$b, $c], array_map('intval', array_column($this->messages->listAfter($this->roomId, 2, $a), 'id')));
        $this->assertSame([$b], array_map('intval', array_column($this->messages->listAfter($this->roomId, 3, $a, 1), 'id')));
        $this->assertSame([], $this->messages->listAfter($this->roomId + 1, 2, 0));
    }

    public function testLatestKeepsTheNewestRoomMessagesOldestFirst(): void
    {
        $a = $this->messages->create($this->roomId, $this->human(2), $this->text('a'));
        $this->messages->create($this->roomId, $this->human(2), $this->text('secret'), ['visibility' => 'private', 'private_to' => 2, 'task_id' => 5]);
        $b = $this->messages->create($this->roomId, $this->bot(), $this->text('b'));
        $c = $this->messages->create($this->roomId, $this->human(3), $this->text('c'));

        $this->assertSame([$a, $b, $c], array_map('intval', array_column($this->messages->latest($this->roomId), 'id')));
        $this->assertSame([$b, $c], array_map('intval', array_column($this->messages->latest($this->roomId, 2), 'id')));
        $this->assertSame([], $this->messages->latest($this->roomId + 1));
    }

    public function testPrivateThreadOnlyForOwner(): void
    {
        $q = $this->messages->create($this->roomId, $this->human(2), $this->text('q'), ['visibility' => 'private', 'private_to' => 2, 'task_id' => 5]);
        $ans = $this->messages->create($this->roomId, $this->bot(), $this->text('ans'), ['visibility' => 'private', 'private_to' => 2, 'task_id' => 5]);
        $this->messages->create($this->roomId, $this->human(2), $this->text('other task'), ['visibility' => 'private', 'private_to' => 2, 'task_id' => 6]);
        $this->messages->create($this->roomId, $this->human(3), $this->text('bob'), ['visibility' => 'private', 'private_to' => 3, 'task_id' => 5]);
        $this->messages->create($this->roomId, $this->human(2), $this->text('room'), ['task_id' => 5]);

        $this->assertSame([$q, $ans], array_map('intval', array_column($this->messages->privateThread($this->roomId, 2, 5), 'id')));
        $this->assertSame(['bob'], array_column($this->messages->privateThread($this->roomId, 3, 5), 'body_text'));
        $this->assertSame([], $this->messages->privateThread($this->roomId, 4, 5));
        // The limit keeps the newest messages, still oldest first.
        $this->assertSame([$ans], array_map('intval', array_column($this->messages->privateThread($this->roomId, 2, 5, 1), 'id')));
    }

    public function testUpdateRewritesPartsTextStatusAndMetadata(): void
    {
        $id = $this->messages->create($this->roomId, $this->bot(), [], ['status' => 'submitted']);
        $this->messages->update($id, ['parts' => $this->text('Answer'), 'status' => 'done', 'metadata' => ['model' => 'm']]);
        $row = $this->messages->getById($id);

        $this->assertSame('Answer', $row['body_text']);
        $this->assertSame('done', $row['status']);
        $this->assertSame(['model' => 'm'], json_decode($row['metadata'], true));

        foreach ([['status' => 'bogus'], ['visibility' => 'private'], ['parts' => ['x']]] as $bad) {
            try {
                $this->messages->update($id, $bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Answer', $this->messages->getById($id)['body_text']);
            }
        }
    }

    public function testToChatPartsMessageRoundTrips(): void
    {
        $parts = [new TextPart('a', 'Hello')];
        $id = $this->messages->create($this->roomId, $this->human(2), $parts, ['metadata' => ['asked_by' => 2]]);
        $msg = $this->messages->toChatPartsMessage($this->messages->getById($id));

        $this->assertInstanceOf(Message::class, $msg);
        $this->assertSame((string) $id, $msg->id);
        $this->assertSame((string) $this->roomId, $msg->room);
        $this->assertSame(['kind' => 'human', 'id' => '2', 'name' => 'Alice A'], $msg->participant->toArray());
        $this->assertSame(MessageStatus::Done, $msg->status);
        $this->assertEquals($parts, $msg->parts);
        $this->assertSame(['asked_by' => 2], $msg->metadata);

        $bot = $this->messages->toChatPartsMessage($this->messages->getById($this->messages->create($this->roomId, $this->bot(), $this->text('b'))));
        $this->assertSame('bot', $bot->participant->kind);
        $this->assertSame('project', $bot->participant->id);
        $bob = $this->messages->toChatPartsMessage($this->messages->getById($this->messages->create($this->roomId, $this->human(3), $this->text('c'))));
        $this->assertSame('bob', $bob->participant->name);
    }

    public function testMalformedStoredRowBecomesAnUnreadableNotice(): void
    {
        $id = $this->messages->create($this->roomId, $this->human(2), $this->text('ok'));
        foreach (['[{"type":"nope","id":"x"}]', '{not json', '"a string"', '[{"type":"text"}]'] as $parts) {
            $this->container['db']->table('agentchat_messages')->eq('id', $id)->update(['parts' => $parts]);
            $msg = $this->messages->toChatPartsMessage($this->messages->getById($id));

            $this->assertSame(MessageStatus::Error, $msg->status, $parts);
            $this->assertSame((string) $id, $msg->id);
            $this->assertSame('human', $msg->participant->kind);
            $this->assertCount(1, $msg->parts);
            $this->assertInstanceOf(TextPart::class, $msg->parts[0]);
            $this->assertSame(t('This message can\'t be shown.'), $msg->parts[0]->text);
            $this->assertTrue($msg->metadata['unreadable']);
        }

        // An unknown stored status or bad metadata is unreadable too, never a fatal.
        $this->container['db']->table('agentchat_messages')->eq('id', $id)->update(['parts' => '[]', 'status' => 'weird']);
        $this->assertSame(MessageStatus::Error, $this->messages->toChatPartsMessage($this->messages->getById($id))->status);
        foreach (['"x"', '{bad'] as $metadata) {
            $this->container['db']->table('agentchat_messages')->eq('id', $id)->update(['status' => 'done', 'metadata' => $metadata]);
            $msg = $this->messages->toChatPartsMessage($this->messages->getById($id));
            $this->assertSame(MessageStatus::Error, $msg->status, $metadata);
            $this->assertTrue($msg->metadata['unreadable'], $metadata);
        }
    }
}
