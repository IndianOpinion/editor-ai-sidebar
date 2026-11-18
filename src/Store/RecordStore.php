<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Store;

/**
 * Storage for result records, job records and run locks.
 *
 * A record is an array with the keys `id`, `kind`, `user_id`, `post_id`,
 * `action_id`, `data` (array), `context_hash`, `created_at`, `expires_at`
 * and `used` (bool). Expiry is the caller's business: `find` returns an
 * expired record so the caller can say `result_expired` rather than guess.
 */
interface RecordStore
{
    public const RESULT = 'result';
    public const JOB = 'job';
    public const LOCK = 'lock';
    public const COUNTER = 'count';

    /**
     * @param array<string, mixed> $data
     */
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
    ): bool;

    /**
     * @return array{id: string, kind: string, user_id: int, post_id: int, action_id: string, data: array<string, mixed>, context_hash: string, created_at: int, expires_at: int, used: bool}|null
     */
    public function find(string $id, string $kind): ?array;

    /**
     * Mark an unexpired result record used. True only for the caller that
     * flipped it: one statement, so two requests cannot both win.
     */
    public function claim(string $id, int $now): bool;

    /**
     * Mark a claimed record unused again.
     */
    public function release(string $id): void;

    /**
     * @param array<string, mixed> $data
     */
    public function updateData(string $id, array $data): void;

    public function delete(string $id): void;

    /**
     * Unexpired jobs for a user and post, newest first.
     *
     * @return array<int, array{id: string, kind: string, user_id: int, post_id: int, action_id: string, data: array<string, mixed>, context_hash: string, created_at: int, expires_at: int, used: bool}>
     */
    public function openJobs(int $userId, int $postId, int $now): array;

    /**
     * Take a lock row; false when an unexpired lock with that id exists.
     */
    public function acquireLock(string $id, int $userId, int $postId, string $actionId, int $now, int $expiresAt): bool;

    /**
     * Add one to a counter row unless it has reached $limit, in one atomic
     * statement. True when this call was counted, false when the limit was
     * already reached. The row is created on first use.
     */
    public function incrementCounter(string $id, string $actionId, int $limit, int $now, int $expiresAt): bool;

    /**
     * Current value of a counter row; 0 when it does not exist.
     */
    public function counter(string $id): int;

    /**
     * Delete rows whose expiry has passed. Returns the number removed.
     */
    public function purgeExpired(int $now): int;
}
