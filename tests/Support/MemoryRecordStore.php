<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Support;

use IndianOpinion\EditorAiSidebar\Store\RecordStore;

/**
 * RecordStore in memory, one table per stub site, for the unit tests.
 * The SQL store is covered by the MySQL integration test.
 */
final class MemoryRecordStore implements RecordStore
{
    /** @var array<int, array<string, array<string, mixed>>> */
    public array $rows = [];

    /**
     * @return array<string, array<string, mixed>>
     */
    private function &table(): array
    {
        $blog = \WpStub::$blogId;
        $this->rows[$blog] ??= [];
        return $this->rows[$blog];
    }

    public function insert(
        string $id,
        string $kind,
        int $userId,
        int $postId,
        string $actionId,
        array $data,
        string $contextHash,
        int $createdAt,
        int $expiresAt
    ): bool {
        $table = &$this->table();
        if (isset($table[$id])) {
            return false;
        }
        $table[$id] = [
            'id' => $id,
            'kind' => $kind,
            'user_id' => $userId,
            'post_id' => $postId,
            'action_id' => $actionId,
            'data' => json_decode((string) json_encode($data), true),
            'context_hash' => $contextHash,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'used' => false,
        ];
        return true;
    }

    public function find(string $id, string $kind): ?array
    {
        $row = $this->table()[$id] ?? null;
        /** @phpstan-ignore return.type */
        return $row !== null && $row['kind'] === $kind ? $row : null;
    }

    public function claim(string $id, int $now): bool
    {
        $table = &$this->table();
        if (!isset($table[$id]) || $table[$id]['kind'] !== self::RESULT || $table[$id]['used'] || $table[$id]['expires_at'] <= $now) {
            return false;
        }
        $table[$id]['used'] = true;
        return true;
    }

    public function release(string $id): void
    {
        $table = &$this->table();
        if (isset($table[$id])) {
            $table[$id]['used'] = false;
        }
    }

    public function updateData(string $id, array $data): void
    {
        $table = &$this->table();
        if (isset($table[$id])) {
            $table[$id]['data'] = json_decode((string) json_encode($data), true);
        }
    }

    public function delete(string $id): void
    {
        $table = &$this->table();
        unset($table[$id]);
    }

    public function openJobs(int $userId, int $postId, int $now): array
    {
        $out = [];
        foreach ($this->table() as $row) {
            if ($row['kind'] === self::JOB && $row['user_id'] === $userId && $row['post_id'] === $postId && $row['expires_at'] > $now) {
                $out[] = $row;
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        /** @phpstan-ignore return.type */
        return $out;
    }

    public function acquireLock(string $id, int $userId, int $postId, string $actionId, int $now, int $expiresAt): bool
    {
        $table = &$this->table();
        if (isset($table[$id]) && $table[$id]['expires_at'] > $now) {
            return false;
        }
        unset($table[$id]);
        return $this->insert($id, self::LOCK, $userId, $postId, $actionId, [], '', $now, $expiresAt);
    }

    public function incrementCounter(string $id, string $actionId, int $limit, int $now, int $expiresAt): bool
    {
        $table = &$this->table();
        if (!isset($table[$id])) {
            $this->insert($id, self::COUNTER, 0, 0, $actionId, [], '', $now, $expiresAt);
            $table[$id]['hits'] = 0;
        }
        if ($table[$id]['kind'] !== self::COUNTER || $table[$id]['hits'] >= $limit) {
            return false;
        }
        $table[$id]['hits']++;
        return true;
    }

    public function counter(string $id): int
    {
        $row = $this->table()[$id] ?? null;
        return $row !== null && $row['kind'] === self::COUNTER ? (int) $row['hits'] : 0;
    }

    public function purgeExpired(int $now): int
    {
        $table = &$this->table();
        $before = count($table);
        $table = array_filter($table, static fn (array $row): bool => $row['expires_at'] > $now);
        return $before - count($table);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function results(): array
    {
        return array_filter($this->table(), static fn (array $row): bool => $row['kind'] === self::RESULT);
    }
}
