<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Store;

/**
 * RecordStore on the small custom table made by Schema.
 *
 * Every query goes through `$wpdb->prepare`. Claim and lock rely on single
 * statements (a conditional UPDATE, and INSERT IGNORE on the primary key),
 * so two requests cannot both win.
 */
final class DbRecordStore implements RecordStore
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    private function table(): string
    {
        return Schema::table($this->db);
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
        $json = wp_json_encode($data);
        if (!is_string($json)) {
            return false;
        }
        $sql = $this->db->prepare(
            'INSERT INTO %i (id, kind, user_id, post_id, action_id, data, context_hash, created_at, expires_at, used)'
            . ' VALUES (%s, %s, %d, %d, %s, %s, %s, %d, %d, 0)',
            $this->table(),
            $id,
            $kind,
            $userId,
            $postId,
            $actionId,
            $json,
            $contextHash,
            $createdAt,
            $expiresAt
        );
        return $this->db->query((string) $sql) === 1;
    }

    public function find(string $id, string $kind): ?array
    {
        $sql = $this->db->prepare('SELECT * FROM %i WHERE id = %s AND kind = %s', $this->table(), $id, $kind);
        $row = $this->db->get_row((string) $sql, ARRAY_A);
        if (!is_array($row) || !hash_equals((string) $row['id'], $id)) {
            return null;
        }
        return self::hydrate($row);
    }

    public function claim(string $id, int $now): bool
    {
        $sql = $this->db->prepare(
            'UPDATE %i SET used = 1 WHERE id = %s AND kind = %s AND used = 0 AND expires_at > %d',
            $this->table(),
            $id,
            self::RESULT,
            $now
        );
        return $this->db->query((string) $sql) === 1;
    }

    public function release(string $id): void
    {
        $sql = $this->db->prepare('UPDATE %i SET used = 0 WHERE id = %s', $this->table(), $id);
        $this->db->query((string) $sql);
    }

    public function updateData(string $id, array $data): void
    {
        $json = wp_json_encode($data);
        if (!is_string($json)) {
            return;
        }
        $sql = $this->db->prepare('UPDATE %i SET data = %s WHERE id = %s', $this->table(), $json, $id);
        $this->db->query((string) $sql);
    }

    public function delete(string $id): void
    {
        $sql = $this->db->prepare('DELETE FROM %i WHERE id = %s', $this->table(), $id);
        $this->db->query((string) $sql);
    }

    public function openJobs(int $userId, int $postId, int $now): array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM %i WHERE kind = %s AND user_id = %d AND post_id = %d AND expires_at > %d'
            . ' ORDER BY created_at DESC LIMIT 50',
            $this->table(),
            self::JOB,
            $userId,
            $postId,
            $now
        );
        $rows = $this->db->get_results((string) $sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::hydrate($row);
            }
        }
        return $out;
    }

    public function acquireLock(string $id, int $userId, int $postId, string $actionId, int $now, int $expiresAt): bool
    {
        $sql = $this->db->prepare(
            'DELETE FROM %i WHERE id = %s AND kind = %s AND expires_at <= %d',
            $this->table(),
            $id,
            self::LOCK,
            $now
        );
        $this->db->query((string) $sql);
        $sql = $this->db->prepare(
            'INSERT IGNORE INTO %i (id, kind, user_id, post_id, action_id, data, context_hash, created_at, expires_at, used)'
            . ' VALUES (%s, %s, %d, %d, %s, %s, %s, %d, %d, 0)',
            $this->table(),
            $id,
            self::LOCK,
            $userId,
            $postId,
            $actionId,
            '{}',
            '',
            $now,
            $expiresAt
        );
        return $this->db->query((string) $sql) === 1;
    }

    public function incrementCounter(string $id, string $actionId, int $limit, int $now, int $expiresAt): bool
    {
        $sql = $this->db->prepare(
            'INSERT IGNORE INTO %i (id, kind, user_id, post_id, action_id, data, context_hash, created_at, expires_at, used, hits)'
            . ' VALUES (%s, %s, 0, 0, %s, %s, %s, %d, %d, 0, 0)',
            $this->table(),
            $id,
            self::COUNTER,
            $actionId,
            '{}',
            '',
            $now,
            $expiresAt
        );
        $this->db->query((string) $sql);
        $sql = $this->db->prepare(
            'UPDATE %i SET hits = hits + 1 WHERE id = %s AND kind = %s AND hits < %d',
            $this->table(),
            $id,
            self::COUNTER,
            $limit
        );
        return $this->db->query((string) $sql) === 1;
    }

    public function counter(string $id): int
    {
        $sql = $this->db->prepare('SELECT hits FROM %i WHERE id = %s AND kind = %s', $this->table(), $id, self::COUNTER);
        $value = $this->db->get_var((string) $sql);
        return is_numeric($value) ? (int) $value : 0;
    }

    public function purgeExpired(int $now): int
    {
        $sql = $this->db->prepare('DELETE FROM %i WHERE expires_at <= %d', $this->table(), $now);
        $deleted = $this->db->query((string) $sql);
        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @param array<mixed> $row
     * @return array{id: string, kind: string, user_id: int, post_id: int, action_id: string, data: array<string, mixed>, context_hash: string, created_at: int, expires_at: int, used: bool}
     */
    private static function hydrate(array $row): array
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);
        return [
            'id' => (string) $row['id'],
            'kind' => (string) $row['kind'],
            'user_id' => (int) $row['user_id'],
            'post_id' => (int) $row['post_id'],
            'action_id' => (string) $row['action_id'],
            'data' => is_array($data) ? $data : [],
            'context_hash' => (string) $row['context_hash'],
            'created_at' => (int) $row['created_at'],
            'expires_at' => (int) $row['expires_at'],
            'used' => (bool) (int) $row['used'],
        ];
    }
}
