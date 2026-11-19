<?php
require_once 'tests/units/Base.php';

use ChatParts\Model\MessageStatus;
use ChatParts\Model\Part\TextPart;
use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Bot\DbCancellationToken;
use Kanboard\Plugin\AgentChat\Model\Busy;
use Kanboard\Plugin\AgentChat\Model\LimitsSettings;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\RateLimited;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;

class RunModelTest extends Base
{
    private int $room;
    /** @var list<int> */
    private array $users = [];
    private RunModel $runs;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../vendor-chatparts/autoload.php';
        require_once dirname(__DIR__, 2).'/AiConnector/vendor/autoload.php';
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AgentChat\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\AgentChat\Schema\version_2($this->container['db']->getConnection());

        $project = (int) $this->container['projectModel']->create(['name' => 'Website']);
        $this->room = (int) (new RoomModel($this->container))->forProject($project)['id'];
        foreach (['ana', 'bob', 'cy', 'di', 'ed'] as $name) {
            $id = (int) $this->container['userModel']->create(['username' => $name, 'name' => ucfirst($name)]);
            $this->container['projectUserRoleModel']->addUser($project, $id, Role::PROJECT_MEMBER);
            $this->users[] = $id;
        }
        $this->runs = new RunModel($this->container);
    }

    private function draft(): int
    {
        return (new MessageModel($this->container))->create($this->room, (new ParticipantResolver($this->container))->bot(), [], ['status' => MessageStatus::Submitted->value]);
    }

    private function question(int $userId): int
    {
        return (new MessageModel($this->container))->create($this->room, (new ParticipantResolver($this->container))->forUser($userId), [new TextPart('t1', '@bot hi?')]);
    }

    private function row(int $runId): array
    {
        return $this->container['db']->table(RunModel::TABLE)->eq('id', $runId)->findOne();
    }

    private function setRun(int $runId, array $values): void
    {
        $this->container['db']->table(RunModel::TABLE)->eq('id', $runId)->update($values);
    }

    // ---- start ----------------------------------------------------------------------------

    public function testSidStoredHashedOnly(): void
    {
        $run = $this->runs->start($this->draft(), $this->room, $this->users[0]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $run['sid'], '32 random bytes as hex');
        $row = $this->row($run['run_id']);
        $this->assertSame(hash('sha256', $run['sid']), $row['stream_token_hash']);
        $this->assertSame('pending', $row['status']);
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($run['sid'], (string) $value, "$column must not hold the sid");
        }
        $this->assertNotSame($run['sid'], $this->runs->start($this->draft(), $this->room, $this->users[1])['sid']);
    }

    public function testRateLimitThirtyPerHour(): void
    {
        $ana = $this->users[0];
        $old = $this->runs->start($this->draft(), $this->room, $ana)['run_id'];
        $this->setRun($old, ['status' => 'done', 'started_at' => time() - 3601]);
        for ($i = 0; $i < 30; $i++) {
            $id = $this->runs->start($this->draft(), $this->room, $ana)['run_id'];
            $this->setRun($id, ['status' => 'done']);
        }

        try {
            $this->runs->start($this->draft(), $this->room, $ana);
            $this->fail('the 31st question within the hour must be refused');
        } catch (RateLimited $e) {
            $this->assertStringContainsString('(30)', $e->getMessage());
        }
        $this->assertSame(31, $this->container['db']->table(RunModel::TABLE)->eq('asked_by', $ana)->count(), 'an hour-old run does not count');
        $this->runs->start($this->draft(), $this->room, $this->users[1]);
        $this->addToAssertionCount(1);
    }

    public function testOneConcurrentPerUser(): void
    {
        $ana = $this->users[0];
        $first = $this->runs->start($this->draft(), $this->room, $ana)['run_id'];

        foreach (['pending', 'running'] as $status) {
            $this->setRun($first, ['status' => $status]);
            try {
                $this->runs->start($this->draft(), $this->room, $ana);
                $this->fail("a $status run blocks a second one");
            } catch (Busy) {
                $this->addToAssertionCount(1);
            }
        }

        $this->setRun($first, ['status' => 'stopped']);
        $this->assertIsInt($this->runs->start($this->draft(), $this->room, $ana)['run_id']);
    }

    public function testInstanceLimitFour(): void
    {
        foreach (array_slice($this->users, 0, 4) as $userId) {
            $this->runs->start($this->draft(), $this->room, $userId);
        }

        try {
            $this->runs->start($this->draft(), $this->room, $this->users[4]);
            $this->fail('a fifth concurrent answer on the instance must be refused');
        } catch (Busy $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function testLimitsCountOnlyAfterReaping(): void
    {
        $limits = new LimitsSettings($this->container);
        $stale = $this->runs->start($this->draft(), $this->room, $this->users[0])['run_id'];
        $this->setRun($stale, ['status' => 'running', 'started_at' => time() - (int) $limits->maxRunSeconds() - RunModel::GRACE_SECONDS - 1]);

        $this->assertIsInt($this->runs->start($this->draft(), $this->room, $this->users[0])['run_id'], 'a stuck run is reaped, not counted');
        $this->assertSame('error', $this->row($stale)['status']);
    }

    // ---- ask ------------------------------------------------------------------------------

    public function testAskCreatesTheBotDraftAndAPendingRun(): void
    {
        $turn = $this->runs->ask($this->room, $this->users[0], $this->question($this->users[0]));

        $draft = (new MessageModel($this->container))->getById($turn['message_id']);
        $this->assertSame('bot', $draft['sender_kind']);
        $this->assertSame('submitted', $draft['status']);
        $this->assertSame($turn['message_id'], (int) $this->row($turn['run_id'])['message_id']);
    }

    public function testAskOverALimitCreatesNoDraft(): void
    {
        $this->runs->ask($this->room, $this->users[0], $this->question($this->users[0]));
        $question = $this->question($this->users[0]);
        $before = $this->container['db']->table(MessageModel::TABLE)->count();

        try {
            $this->runs->ask($this->room, $this->users[0], $question);
            $this->fail('expected Busy');
        } catch (Busy) {
        }
        $this->assertSame($before, $this->container['db']->table(MessageModel::TABLE)->count());
    }

    public function testOneRunPerQuestion(): void
    {
        $question = $this->question($this->users[0]);
        $first = $this->runs->ask($this->room, $this->users[0], $question);

        $again = $this->runs->ask($this->room, $this->users[0], $question);

        $this->assertSame($first['run_id'], $again['run_id']);
        $this->assertSame($first['message_id'], $again['message_id']);
        $this->assertNull($again['sid'], 'the stream id of the existing run is not handed out again');
        $this->assertSame(1, $this->container['db']->table(RunModel::TABLE)->count());
        $this->assertSame($question, (int) (new MessageModel($this->container))->getById($first['message_id'])['reply_to']);
        // The per-user limit would refuse a new run here; an existing run is returned regardless.
        $this->setRun($first['run_id'], ['status' => 'done']);
        $this->assertSame($first['run_id'], $this->runs->ask($this->room, $this->users[0], $question)['run_id']);
    }

    public function testStartReturnsTheExistingRunOfAMessage(): void
    {
        $draft = $this->draft();
        $first = $this->runs->start($draft, $this->room, $this->users[0]);

        $again = $this->runs->start($draft, $this->room, $this->users[0]);

        $this->assertSame(['run_id' => $first['run_id'], 'sid' => null], $again);
        $this->assertSame(1, $this->container['db']->table(RunModel::TABLE)->count());
    }

    // ---- claim ----------------------------------------------------------------------------

    public function testClaimIsSingleUseAndOnlyForTheAsker(): void
    {
        $run = $this->runs->start($this->draft(), $this->room, $this->users[0]);

        $this->assertNull($this->runs->claim($run['sid'], $this->users[1]), "another user's sid");
        $this->assertNull($this->runs->claim(str_repeat('0', 64), $this->users[0]), 'unknown sid');
        $this->assertNull($this->runs->claim('', $this->users[0]), 'empty sid');

        $claimed = $this->runs->claim($run['sid'], $this->users[0]);
        $this->assertSame($run['run_id'], (int) $claimed['id']);
        $this->assertSame('running', $claimed['status']);
        $this->assertNull($this->runs->claim($run['sid'], $this->users[0]), 'a spent sid');
    }

    public function testFinishedRunCannotBeClaimed(): void
    {
        $run = $this->runs->start($this->draft(), $this->room, $this->users[0]);
        $this->setRun($run['run_id'], ['status' => 'error']);

        $this->assertNull($this->runs->claim($run['sid'], $this->users[0]));
        $this->assertFalse($this->runs->claimById($run['run_id']));
    }

    public function testClaimByIdOnce(): void
    {
        $run = $this->runs->start($this->draft(), $this->room, $this->users[0]);

        $this->assertTrue($this->runs->claimById($run['run_id']));
        $this->assertFalse($this->runs->claimById($run['run_id']));
        $this->assertNull($this->runs->claim($run['sid'], $this->users[0]), 'claimed by id: the sid is spent too');
    }

    // ---- stop -----------------------------------------------------------------------------

    public function testRequestStopIsTheAskersOnly(): void
    {
        $run = $this->runs->start($this->draft(), $this->room, $this->users[0])['run_id'];

        $this->assertFalse($this->runs->requestStop($run, $this->users[1]));
        $this->assertSame(0, (int) $this->row($run)['stop_requested']);
        $this->assertFalse($this->runs->requestStop($run + 100, $this->users[0]), 'unknown run');

        $this->assertTrue($this->runs->requestStop($run, $this->users[0]));
        $this->assertSame(DbCancellationToken::STOPPED_BY_ASKER, (int) $this->row($run)['stop_requested']);
    }

    public function testRequestStopOnAFinishedRunChangesNothing(): void
    {
        $run = $this->runs->start($this->draft(), $this->room, $this->users[0])['run_id'];
        $this->setRun($run, ['status' => 'done']);

        $this->assertTrue($this->runs->requestStop($run, $this->users[0]));
        $this->assertSame(0, (int) $this->row($run)['stop_requested']);
    }

    public function testSystemStopKeepsItsOwnFlag(): void
    {
        $run = $this->runs->start($this->draft(), $this->room, $this->users[0])['run_id'];

        $this->runs->requestStop($run, $this->users[0], DbCancellationToken::STOPPED);
        $this->assertSame(DbCancellationToken::STOPPED, (int) $this->row($run)['stop_requested']);
    }

    public function testActiveRunsOfAUserInARoom(): void
    {
        $mine = $this->runs->start($this->draft(), $this->room, $this->users[0])['run_id'];
        $this->runs->start($this->draft(), $this->room, $this->users[1]);

        $this->assertSame([$mine], $this->runs->activeIds($this->users[0], $this->room));
        $this->setRun($mine, ['status' => 'done']);
        $this->assertSame([], $this->runs->activeIds($this->users[0], $this->room));
    }

    // ---- finish and reap ------------------------------------------------------------------

    public function testFinishClosesTheRunRow(): void
    {
        $run = $this->runs->start($this->draft(), $this->room, $this->users[0])['run_id'];

        $this->runs->finish($run, 'done', ['total_tokens' => 7]);

        $row = $this->row($run);
        $this->assertSame('done', $row['status']);
        $this->assertGreaterThan(0, (int) $row['finished_at']);
        $this->assertSame(['total_tokens' => 7], json_decode($row['usage'], true));
    }

    public function testReaperFinalisesStaleRunsAsError(): void
    {
        $limits = new LimitsSettings($this->container);
        $cutoff = time() - (int) $limits->maxRunSeconds() - RunModel::GRACE_SECONDS;
        $messages = new MessageModel($this->container);

        // ask() reaps too, so every run exists before any is made stale.
        [$stalePending, $staleRunning, $fresh, $done] = array_map(fn (int $u): array => $this->runs->ask($this->room, $u, $this->question($u)), array_slice($this->users, 0, 4));
        $this->setRun($stalePending['run_id'], ['started_at' => $cutoff - 1]);
        $this->setRun($staleRunning['run_id'], ['status' => 'running', 'started_at' => $cutoff - 5]);
        $this->setRun($fresh['run_id'], ['status' => 'running', 'started_at' => $cutoff + 5]);
        $this->setRun($done['run_id'], ['status' => 'done', 'started_at' => $cutoff - 500]);

        $this->assertSame(2, $this->runs->reap());

        foreach ([$stalePending, $staleRunning] as $turn) {
            $row = $this->row($turn['run_id']);
            $this->assertSame('error', $row['status']);
            $this->assertGreaterThan(0, (int) $row['finished_at']);
            $this->assertSame('timed_out', json_decode($row['usage'], true)['error_code']);

            $message = $messages->toChatPartsMessage($messages->getById($turn['message_id']));
            $this->assertSame(MessageStatus::Error, $message->status);
            $this->assertSame('The bot could not answer.', $message->plainText());
            $this->assertSame('error', $message->metadata['finish_reason']);
            $this->assertSame('Project bot', $message->metadata['bot']);
        }
        $this->assertSame('running', $this->row($fresh['run_id'])['status']);
        $this->assertSame('done', $this->row($done['run_id'])['status']);
        $this->assertSame('submitted', $messages->getById($done['message_id'])['status'], 'a finished run is never touched');
        $this->assertSame(0, $this->runs->reap(), 'reaping twice does nothing more');
    }
}
