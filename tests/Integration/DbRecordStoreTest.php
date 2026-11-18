<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Integration;

use IndianOpinion\EditorAiSidebar\Store\DbRecordStore;
use IndianOpinion\EditorAiSidebar\Store\RecordStore;
use IndianOpinion\EditorAiSidebar\Store\Schema;
use IndianOpinion\EditorAiSidebar\Tests\Support\MysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * The SQL store against a real MySQL server. Skipped unless
 * EDITOR_AI_SIDEBAR_TEST_MYSQL is set to `host:port` or a socket path
 * (user root, no password, database `editor_ai_sidebar_test`).
 */
final class DbRecordStoreTest extends TestCase
{
    private MysqliWpdb $db;
    private DbRecordStore $store;

    protected function setUp(): void
    {
        $target = getenv('EDITOR_AI_SIDEBAR_TEST_MYSQL');
        if ($target === false || $target === '') {
            self::markTestSkipped('Set EDITOR_AI_SIDEBAR_TEST_MYSQL to run the MySQL integration test.');
        }
        \WpStub::reset();
        mysqli_report(MYSQLI_REPORT_OFF);
        $isSocket = str_starts_with($target, '/');
        [$host, $port] = $isSocket ? ['localhost', 0] : array_pad(explode(':', $target), 2, '3306');
        $link = new \mysqli($host, 'root', '', '', (int) $port, $isSocket ? $target : null);
        if ($link->connect_errno !== 0) {
            self::fail('MySQL connection failed: ' . $link->connect_error);
        }
        $link->query('CREATE DATABASE IF NOT EXISTS editor_ai_sidebar_test');
        $link->select_db('editor_ai_sidebar_test');
        $link->set_charset('utf8mb4');
        $this->db = new MysqliWpdb($link);
        $this->db->query('DROP TABLE IF EXISTS ' . Schema::table($this->db));
        // dbDelta is WordPress code; the statement it is given is ours, and MySQL must accept it as is.
        self::assertTrue($this->db->query(Schema::createSql($this->db)));
        $this->store = new DbRecordStore($this->db);
    }

    public function testInsertFindAndHydrate(): void
    {
        $id = bin2hex(random_bytes(16));
        $data = ['items' => [['id' => 'a', 'value' => 'हिन्दी "quoted" \\ text']], 'applied' => []];
        self::assertTrue($this->store->insert($id, RecordStore::RESULT, 2, 10, 'p/a', $data, str_repeat('c', 64), 100, 200));
        self::assertFalse($this->store->insert($id, RecordStore::RESULT, 2, 10, 'p/a', $data, '', 100, 200), 'ids are unique');
        $row = $this->store->find($id, RecordStore::RESULT);
        self::assertNotNull($row);
        self::assertSame($data, $row['data']);
        self::assertSame(2, $row['user_id']);
        self::assertSame(200, $row['expires_at']);
        self::assertFalse($row['used']);
        self::assertNull($this->store->find($id, RecordStore::JOB), 'kind must match');
        self::assertNull($this->store->find(strtoupper($id), RecordStore::RESULT), 'ids compare exactly');
    }

    public function testClaimIsSingleWinner(): void
    {
        $id = bin2hex(random_bytes(16));
        $this->store->insert($id, RecordStore::RESULT, 2, 10, 'p/a', [], '', 100, 200);
        self::assertTrue($this->store->claim($id, 150));
        self::assertFalse($this->store->claim($id, 150));
        $this->store->release($id);
        self::assertFalse($this->store->claim($id, 200), 'an expired record cannot be claimed');
        self::assertTrue($this->store->claim($id, 150));
        self::assertFalse($this->store->claim('missing', 150));
        $job = bin2hex(random_bytes(16));
        $this->store->insert($job, RecordStore::JOB, 2, 10, 'p/a', [], '', 100, 200);
        self::assertFalse($this->store->claim($job, 150), 'only result records are claimed');
    }

    public function testCounterStopsAtLimitUnderInterleavedCalls(): void
    {
        $id = 'cap-' . md5('p/a') . '-20261118';
        self::assertSame(0, $this->store->counter($id));
        $second = new DbRecordStore($this->db);
        self::assertTrue($this->store->incrementCounter($id, 'p/a', 2, 100, 500));
        self::assertTrue($second->incrementCounter($id, 'p/a', 2, 100, 500));
        self::assertFalse($this->store->incrementCounter($id, 'p/a', 2, 100, 500));
        self::assertFalse($second->incrementCounter($id, 'p/a', 2, 100, 500));
        self::assertSame(2, $this->store->counter($id));
        self::assertTrue($this->store->incrementCounter($id, 'p/a', 3, 100, 500), 'a raised cap allows one more');
        self::assertSame(3, $second->counter($id));
    }

    public function testUpdateDataAndDelete(): void
    {
        $id = bin2hex(random_bytes(16));
        $this->store->insert($id, RecordStore::JOB, 2, 10, 'p/a', ['ref' => 'x'], '', 100, 200);
        $this->store->updateData($id, ['ref' => 'y', 'poll_after' => 6]);
        self::assertSame(['ref' => 'y', 'poll_after' => 6], $this->store->find($id, RecordStore::JOB)['data'] ?? null);
        $this->store->delete($id);
        self::assertNull($this->store->find($id, RecordStore::JOB));
    }

    public function testOpenJobs(): void
    {
        $this->store->insert('j1' . str_repeat('0', 30), RecordStore::JOB, 2, 10, 'p/a', [], '', 100, 500);
        $this->store->insert('j2' . str_repeat('0', 30), RecordStore::JOB, 2, 10, 'p/b', [], '', 150, 500);
        $this->store->insert('j3' . str_repeat('0', 30), RecordStore::JOB, 2, 10, 'p/c', [], '', 150, 120);
        $this->store->insert('j4' . str_repeat('0', 30), RecordStore::JOB, 3, 10, 'p/a', [], '', 150, 500);
        $this->store->insert('r1' . str_repeat('0', 30), RecordStore::RESULT, 2, 10, 'p/a', [], '', 150, 500);
        $jobs = $this->store->openJobs(2, 10, 130);
        self::assertSame(['p/b', 'p/a'], array_column($jobs, 'action_id'));
    }

    public function testLocksAreExclusiveUntilExpiry(): void
    {
        self::assertTrue($this->store->acquireLock('lock-1', 2, 10, 'p/a', 100, 190));
        self::assertFalse($this->store->acquireLock('lock-1', 2, 10, 'p/a', 150, 240));
        self::assertTrue($this->store->acquireLock('lock-1', 2, 10, 'p/a', 190, 280), 'expired lock is replaced');
        $this->store->delete('lock-1');
        self::assertTrue($this->store->acquireLock('lock-1', 2, 10, 'p/a', 200, 290));
    }

    public function testPurgeExpired(): void
    {
        $this->store->insert('old' . str_repeat('0', 29), RecordStore::RESULT, 2, 10, 'p/a', [], '', 1, 50);
        $this->store->insert('new' . str_repeat('0', 29), RecordStore::RESULT, 2, 10, 'p/a', [], '', 1, 500);
        self::assertSame(1, $this->store->purgeExpired(100));
        self::assertNotNull($this->store->find('new' . str_repeat('0', 29), RecordStore::RESULT));
    }
}
