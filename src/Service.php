<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

use IndianOpinion\EditorAiSidebar\Store\RecordStore;

/**
 * The host's rules: listing, running, polling, applying and dismissing.
 *
 * Every method either returns a response body or throws ApiError. The REST
 * layer is a thin shell around this class, so the rules are tested here.
 */
final class Service
{
    private const ID_PATTERN = '/^[a-f0-9]{32}$/';
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

    /** @var \Closure(): bool */
    private \Closure $clientGone;

    public function __construct(
        private readonly Registry $registry,
        private readonly RecordStore $store,
        private readonly Limiter $limiter,
        private readonly EventLog $log,
        private readonly ResultValidator $validator,
        private readonly WriteWatch $watch,
        private readonly FatalGuard $guard
    ) {
        $this->clientGone = static fn (): bool => connection_aborted() === 1;
    }

    /**
     * Replace the check for a browser that went away (tests).
     *
     * @param \Closure(): bool $check
     */
    public function setClientGoneCheck(\Closure $check): void
    {
        $this->clientGone = $check;
    }

    // ------------------------------------------------------------------
    // Listing (cases 14 to 18, ruling 1)
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActions(mixed $postId, int $userId): array
    {
        $post = $this->post($postId);
        $now = Clock::now();
        $ttl = self::resultTtl();
        $jobs = [];
        foreach ($this->store->openJobs($userId, $post->ID, $now) as $job) {
            if ($job['created_at'] + $ttl <= $now || isset($jobs[$job['action_id']])) {
                continue;
            }
            $jobs[$job['action_id']] = $job;
        }

        $out = [];
        foreach ($this->registry->enabledFor($post->ID) as $definition) {
            $descriptor = $definition->descriptor();
            [$available, $reason] = $this->availability($definition, $post->ID, $userId);
            $descriptor['available'] = $available;
            $descriptor['reason'] = $reason;
            $descriptor['can_apply'] = current_user_can($definition->applyCapability(), $post->ID)
                && current_user_can('edit_post', $post->ID);
            $descriptor['pending_job'] = null;
            if (isset($jobs[$definition->id()])) {
                $job = $jobs[$definition->id()];
                $descriptor['pending_job'] = [
                    'job_id' => $job['id'],
                    'poll_after' => (int) ($job['data']['poll_after'] ?? 3),
                    'elapsed' => max(0, $now - $job['created_at']),
                    'client_id' => is_string($job['data']['context']['selection']['client_id'] ?? null)
                        ? $job['data']['context']['selection']['client_id']
                        : null,
                ];
            }
            $out[] = $descriptor;
        }
        usort($out, [self::class, 'compareDescriptors']);
        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compareDescriptors(array $a, array $b): int
    {
        $groups = array_flip(ActionDefinition::GROUPS);
        return [$groups[$a['group']], $a['order'], $a['label'], $a['id']]
            <=> [$groups[$b['group']], $b['order'], $b['label'], $b['id']];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function availability(ActionDefinition $definition, int $postId, int $userId): array
    {
        if (!current_user_can($definition->runCapability(), $postId)) {
            return [false, __('You do not have permission to run this action.', 'editor-ai-sidebar')];
        }
        $available = $definition->callback('available');
        if ($available === null) {
            return [true, ''];
        }
        try {
            $answer = $available($postId, $userId);
        } catch (\Throwable $e) {
            $this->violation($definition->id(), 'available_threw', ['class' => get_class($e)]);
            return [false, __('This action is not available right now.', 'editor-ai-sidebar')];
        }
        if ($answer === true) {
            return [true, ''];
        }
        if (is_string($answer) && trim($answer) !== '') {
            return [false, mb_substr(trim(wp_strip_all_tags($answer)), 0, 300)];
        }
        $this->violation($definition->id(), 'available_return', []);
        return [false, __('This action is not available right now.', 'editor-ai-sidebar')];
    }

    // ------------------------------------------------------------------
    // Running (cases 23 to 41)
    // ------------------------------------------------------------------

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function run(string $actionId, mixed $postId, mixed $context, mixed $runToken, int $userId): array
    {
        $post = $this->post($postId);
        $definition = $this->registry->forPostType($post->post_type)[$actionId] ?? null;
        if ($definition === null) {
            throw $this->error('action_unknown', 404);
        }
        if (!$this->registry->isEnabled($definition, $post->ID)) {
            throw $this->error('action_disabled', 403);
        }
        if (!current_user_can($definition->runCapability(), $post->ID)) {
            throw $this->error('rest_forbidden', 403);
        }
        $this->checkSelection($definition, $context);
        /** @var array<string, mixed> $context */
        $this->checkContextSize($context);
        $context = $this->checkContextShape($definition, $context);

        $retry = $this->limiter->hitRateLimit($userId);
        if ($retry > 0) {
            throw new ApiError('rate_limited', Messages::forCode('rate_limited'), 429, ['retry_after' => $retry]);
        }
        if ($this->limiter->dailyCapReached($definition)) {
            $this->log->add('run_failed', [
                'action_id' => $actionId, 'post_id' => $post->ID, 'user_id' => $userId, 'code' => 'daily_cap_reached',
            ]);
            throw $this->error('daily_cap_reached', 429);
        }
        [$available, $reason] = $this->availability($definition, $post->ID, $userId);
        if (!$available) {
            throw new ApiError('action_unavailable', $reason, 409);
        }

        // Lock first, then look for an open job, and hold the lock until a
        // job record (if any) is stored: no window for a second run (case 26).
        $now = Clock::now();
        $lockId = 'lock-' . md5($userId . '|' . $post->ID . '|' . $actionId);
        if (!$this->store->acquireLock($lockId, $userId, $post->ID, $actionId, $now, $now + $definition->timeout() + 30)) {
            throw $this->error('run_in_progress', 409);
        }
        try {
            foreach ($this->store->openJobs($userId, $post->ID, Clock::now()) as $job) {
                if ($job['action_id'] === $actionId) {
                    throw $this->error('run_in_progress', 409);
                }
            }
            if (!$this->limiter->takeDaily($definition)) {
                $this->log->add('run_failed', [
                    'action_id' => $actionId, 'post_id' => $post->ID, 'user_id' => $userId, 'code' => 'daily_cap_reached',
                ]);
                throw $this->error('daily_cap_reached', 429);
            }
            $this->log->add('run_started', ['action_id' => $actionId, 'post_id' => $post->ID, 'user_id' => $userId]);
            do_action('editor_ai_sidebar_run_started', $actionId, $post->ID, $userId);

            $request = $this->runRequest($definition, $post->ID, $userId, $context);
            $run = $definition->runCallback();
            [$value, $written, $thrown] = $this->guarded(
                $definition,
                $post->ID,
                $userId,
                $request->selection(),
                fn (): mixed => $run($this->wantsArray($run, 0) ? $request->toArray() : $request)
            );

            if ($thrown !== null) {
                $this->providerThrew($definition, $post->ID, $userId, $thrown);
            }
            if ($this->clientIsGone($userId, $runToken)) {
                $this->log->add('run_failed', [
                    'action_id' => $actionId, 'post_id' => $post->ID, 'user_id' => $userId, 'code' => 'run_cancelled',
                ]);
                throw $this->error('run_cancelled', 409);
            }
            return $this->interpret($definition, $request, $value, $written, null);
        } finally {
            $this->store->delete($lockId);
        }
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function poll(string $jobId, int $userId): array
    {
        $job = $this->job($jobId, $userId);
        $post = $this->post($job['post_id']);
        $definition = $this->registry->forPostType($post->post_type)[$job['action_id']] ?? null;
        if ($definition === null || !$this->registry->isEnabled($definition, $post->ID)) {
            $this->store->delete($job['id']);
            throw $this->error('action_gone', 410);
        }
        $poll = $definition->callback('poll');
        if ($poll === null) {
            $this->store->delete($job['id']);
            throw $this->error('action_gone', 410);
        }
        $request = $this->requestFromStored($definition, $job);
        $ref = (string) ($job['data']['ref'] ?? '');
        [$value, $written, $thrown] = $this->guarded(
            $definition,
            $post->ID,
            $userId,
            $request->selection(),
            fn (): mixed => $poll($ref, $this->wantsArray($poll, 1) ? $request->toArray() : $request)
        );
        if ($thrown !== null) {
            $this->store->delete($job['id']);
            $this->providerThrew($definition, $post->ID, $userId, $thrown);
        }
        try {
            $answer = $this->interpret($definition, $request, $value, $written, $job);
        } catch (ApiError $e) {
            $this->store->delete($job['id']);
            throw $e;
        }
        if ($answer['status'] !== 202) {
            $this->store->delete($job['id']);
        }
        return $answer;
    }

    public function cancelJob(string $jobId, int $userId): void
    {
        if (preg_match(self::TOKEN_PATTERN, $jobId) !== 1) {
            throw $this->error('job_gone', 404);
        }
        $job = preg_match(self::ID_PATTERN, $jobId) === 1 ? $this->store->find($jobId, RecordStore::JOB) : null;
        if ($job === null || $job['user_id'] !== $userId) {
            // Not a job: remember the id as a cancelled synchronous run token.
            set_transient(self::cancelKey($userId, $jobId), 1, 600);
            return;
        }
        $definition = $this->registry->find($job['action_id']);
        $cancel = $definition?->callback('cancel');
        if ($definition !== null && $cancel !== null) {
            try {
                $cancel((string) ($job['data']['ref'] ?? ''));
            } catch (\Throwable $e) {
                $this->violation($definition->id(), 'cancel_threw', ['class' => get_class($e)]);
            }
        }
        $this->store->delete($job['id']);
        $this->log->add('job_cancelled', [
            'action_id' => $job['action_id'], 'post_id' => $job['post_id'], 'user_id' => $userId,
        ]);
    }

    // ------------------------------------------------------------------
    // Applying and dismissing (cases 46 to 61)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function apply(string $resultId, array $params, int $userId): array
    {
        if (($params['approved'] ?? null) !== true) {
            throw $this->error('approval_missing', 400);
        }
        $record = $this->result($resultId, $userId, $params);
        $post = $this->post($record['post_id']);
        if ($record['used']) {
            throw $this->error('result_used', 409);
        }
        $definition = $this->registry->forPostType($post->post_type)[$record['action_id']] ?? null;
        if ($definition === null) {
            throw $this->error('action_gone', 410);
        }
        if (!$this->registry->isEnabled($definition, $post->ID)) {
            throw $this->error('action_disabled', 403);
        }
        if (!current_user_can($definition->applyCapability(), $post->ID) || !current_user_can('edit_post', $post->ID)) {
            throw $this->error('rest_forbidden', 403);
        }
        if (!function_exists('wp_check_post_lock')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
        $lockedBy = wp_check_post_lock($post->ID);
        if ($lockedBy !== false && (int) $lockedBy !== $userId) {
            throw $this->error('post_locked', 409);
        }

        $itemId = is_string($params['item_id'] ?? null) ? $params['item_id'] : '';
        $stored = null;
        foreach ((array) ($record['data']['items'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $itemId) {
                $stored = $candidate;
                break;
            }
        }
        if ($stored === null) {
            throw $this->error('item_unknown', 400);
        }
        $applied = (array) ($record['data']['applied'] ?? []);
        if (in_array($itemId, $applied, true)) {
            throw $this->error('result_used', 409);
        }

        $request = $this->requestFromStored($definition, $record);
        [$item, $edited] = $this->finalItem($definition, $stored, $params['value'] ?? null, $request);

        $contextHash = is_string($params['context_hash'] ?? null) ? $params['context_hash'] : '';
        $confirmStale = ($params['confirm_stale'] ?? false) === true;
        if (!hash_equals($record['context_hash'], $contextHash) && !$confirmStale) {
            throw $this->error('context_changed', 409);
        }

        if (!$this->store->claim($record['id'], Clock::now())) {
            throw $this->error('result_used', 409);
        }
        // The claim serialises applies. Re-read the record under it so a
        // reapply cannot apply one item twice or lose another's mark (case 60).
        $fresh = $this->store->find($record['id'], RecordStore::RESULT);
        $applied = (array) ($fresh['data']['applied'] ?? []);
        if ($fresh === null || in_array($itemId, $applied, true)) {
            $this->store->release($record['id']);
            throw $this->error('result_used', 409);
        }
        $record = $fresh;

        if (!$definition->isServerMode()) {
            $this->afterApply($definition, $record, $itemId, $applied);
            $this->applied($definition, $post->ID, $userId, $record['id'], $itemId, $edited, 'editor');
            return [
                'mode' => 'editor',
                'operation' => $definition->operation(),
                'targets' => $definition->targets(),
                'value' => $item->value(),
                'message' => '',
                'links' => [],
                'position' => $definition->position(),
                'attribute' => $definition->attribute(),
                'meta_map' => (object) $definition->metaMap(),
                'sync' => (object) [],
            ];
        }

        return $this->serverApply($definition, $post, $record, $item, $edited, $confirmStale, $userId, $applied);
    }

    /**
     * @param array{id: string, kind: string, user_id: int, post_id: int, action_id: string, data: array<string, mixed>, context_hash: string, created_at: int, expires_at: int, used: bool} $record
     * @param array<int, mixed> $applied
     * @return array<string, mixed>
     */
    private function serverApply(
        ActionDefinition $definition,
        \WP_Post $post,
        array $record,
        Item $item,
        bool $edited,
        bool $confirmStale,
        int $userId,
        array $applied
    ): array {
        $callback = $definition->callback('apply_callback');
        if ($callback === null) {
            $this->store->release($record['id']);
            throw $this->error('action_gone', 410);
        }
        $request = new ApplyRequest(
            $definition->id(),
            $post->ID,
            $userId,
            $record['id'],
            $item,
            $edited,
            $confirmStale
        );
        $statusBefore = get_post_status($post->ID);
        $this->guard->arm($definition->id(), $post->ID, $userId);
        try {
            $value = $callback($this->wantsArray($callback, 0) ? $request->toArray() : $request);
        } catch (\Throwable $e) {
            $this->store->release($record['id']);
            $this->log->add('apply_failed', [
                'action_id' => $definition->id(), 'post_id' => $post->ID, 'user_id' => $userId,
                'result_id' => $record['id'], 'code' => 'provider_error', 'detail' => self::describe($e),
            ]);
            self::debugLog($definition->id(), $e);
            $this->violation($definition->id(), 'provider_error', ['class' => get_class($e), 'phase' => 'apply']);
            throw $this->error('provider_error', 500);
        } finally {
            $this->guard->disarm();
        }

        if ($value instanceof \WP_Error) {
            $data = $value->get_error_data();
            $final = is_array($data) && ($data['final'] ?? false) === true;
            if (!$final) {
                $this->store->release($record['id']);
            }
            $this->log->add('apply_failed', [
                'action_id' => $definition->id(), 'post_id' => $post->ID, 'user_id' => $userId,
                'result_id' => $record['id'], 'code' => (string) $value->get_error_code(),
            ]);
            throw $this->passThrough($value);
        }

        try {
            $outcome = $this->validator->outcome($value);
        } catch (ContractViolation $e) {
            // The provider may already have written, so the record stays used.
            $this->violation($definition->id(), 'invalid_outcome', ['rule' => $e->getMessage()]);
            $this->log->add('apply_failed', [
                'action_id' => $definition->id(), 'post_id' => $post->ID, 'user_id' => $userId,
                'result_id' => $record['id'], 'code' => 'provider_error', 'detail' => $e->getMessage(),
            ]);
            throw $this->error('provider_error', 500);
        }

        $statusAfter = get_post_status($post->ID);
        $reportsCore = array_intersect($outcome['targets'], array_merge(ActionDefinition::CORE_FIELDS, ['post_status']));
        if ($statusAfter !== $statusBefore || $reportsCore !== []) {
            $this->violation($definition->id(), 'status_changed', [
                'post_id' => $post->ID,
                'before' => $statusBefore,
                'after' => $statusAfter,
                'targets' => array_values($reportsCore),
            ]);
        }
        if ($outcome['dropped_links'] > 0) {
            $this->violation($definition->id(), 'foreign_link', ['count' => $outcome['dropped_links']]);
        }

        $this->afterApply($definition, $record, $item->id(), $applied);
        $this->applied($definition, $post->ID, $userId, $record['id'], $item->id(), $edited, 'server');

        $selection = $this->requestFromStored($definition, $record)->selection();
        return [
            'mode' => 'server',
            'operation' => null,
            'targets' => $outcome['targets'],
            'value' => $item->value(),
            'message' => $outcome['message'],
            'links' => $outcome['links'],
            'position' => null,
            'attribute' => null,
            'meta_map' => (object) [],
            'sync' => $this->syncData($post, $outcome['targets'], $selection),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function dismiss(string $resultId, array $params, int $userId): void
    {
        $record = $this->result($resultId, $userId, $params);
        if ($record['used']) {
            throw $this->error('result_used', 409);
        }
        if (!$this->store->claim($record['id'], Clock::now())) {
            throw $this->error('result_used', 409);
        }
        $definition = $this->registry->find($record['action_id']);
        $dismiss = $definition?->callback('dismiss');
        if ($definition !== null && $dismiss !== null) {
            $items = [];
            foreach ((array) ($record['data']['items'] ?? []) as $stored) {
                if (is_array($stored)) {
                    $items[] = $this->itemFromStored($stored);
                }
            }
            try {
                // Always Item objects: the parameter is a list either way (section 5.2).
                $dismiss($record['id'], $items, $userId);
            } catch (\Throwable $e) {
                $this->violation($definition->id(), 'dismiss_threw', ['class' => get_class($e)]);
            }
        }
        $this->log->add('dismissed', [
            'action_id' => $record['action_id'], 'post_id' => $record['post_id'], 'user_id' => $userId,
            'result_id' => $record['id'],
        ]);
        do_action('editor_ai_sidebar_dismissed', $record['action_id'], $record['post_id'], $userId, $record['id']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function resultTtl(): int
    {
        return max(60, min(86400, (int) apply_filters('editor_ai_sidebar_result_ttl', 1800)));
    }

    public static function contextLimit(): int
    {
        return max(1024, (int) apply_filters('editor_ai_sidebar_context_limit', 524288));
    }

    private function error(string $code, int $status): ApiError
    {
        return new ApiError($code, Messages::forCode($code), $status);
    }

    private function post(mixed $postId): \WP_Post
    {
        $id = is_numeric($postId) ? (int) $postId : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (!$post instanceof \WP_Post || $post->post_status === 'trash') {
            throw $this->error('post_missing', 404);
        }
        if (!current_user_can('edit_post', $post->ID)) {
            throw $this->error('rest_forbidden', 403);
        }
        return $post;
    }

    /**
     * @return array{id: string, kind: string, user_id: int, post_id: int, action_id: string, data: array<string, mixed>, context_hash: string, created_at: int, expires_at: int, used: bool}
     */
    private function job(string $jobId, int $userId): array
    {
        $job = preg_match(self::ID_PATTERN, $jobId) === 1 ? $this->store->find($jobId, RecordStore::JOB) : null;
        if ($job === null || $job['expires_at'] <= Clock::now() || $job['user_id'] !== $userId) {
            throw $this->error('job_gone', 404);
        }
        return $job;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{id: string, kind: string, user_id: int, post_id: int, action_id: string, data: array<string, mixed>, context_hash: string, created_at: int, expires_at: int, used: bool}
     */
    private function result(string $resultId, int $userId, array $params): array
    {
        $record = preg_match(self::ID_PATTERN, $resultId) === 1
            ? $this->store->find($resultId, RecordStore::RESULT)
            : null;
        if ($record === null || $record['expires_at'] <= Clock::now()) {
            throw $this->error('result_expired', 410);
        }
        if ($record['user_id'] !== $userId) {
            throw $this->error('not_your_result', 403);
        }
        $postId = is_numeric($params['post_id'] ?? null) ? (int) $params['post_id'] : 0;
        $actionId = is_string($params['action_id'] ?? null) ? $params['action_id'] : '';
        if ($postId !== $record['post_id'] || !hash_equals($record['action_id'], $actionId)) {
            throw $this->error('result_mismatch', 400);
        }
        return $record;
    }

    private function checkSelection(ActionDefinition $definition, mixed $context): void
    {
        $blocks = $definition->selection();
        if ($blocks === null) {
            return;
        }
        $selection = is_array($context) ? ($context['selection'] ?? null) : null;
        $name = is_array($selection) ? ($selection['name'] ?? null) : null;
        if (!is_string($name) || !in_array($name, $blocks, true)) {
            throw $this->error('selection_required', 400);
        }
    }

    private function checkContextSize(mixed $context): void
    {
        try {
            $size = strlen(Canonical::encode($context));
        } catch (\JsonException) {
            throw $this->error('context_invalid', 400);
        }
        if ($size > self::contextLimit()) {
            throw $this->error('context_too_large', 413);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkContextShape(ActionDefinition $definition, mixed $context): array
    {
        if (!is_array($context) || ($context !== [] && array_is_list($context))) {
            throw $this->error('context_invalid', 400);
        }
        $needs = $definition->needs();
        foreach (array_keys($context) as $key) {
            if (!is_string($key) || !in_array($key, $needs, true)) {
                throw $this->error('context_invalid', 400);
            }
        }
        foreach ($needs as $need) {
            if (!array_key_exists($need, $context)) {
                throw $this->error('context_invalid', 400);
            }
            $value = $context[$need];
            $ok = match ($need) {
                'featured_media' => is_int($value) && $value >= 0,
                'selection' => $this->validSelection($definition, $value),
                'language' => is_string($value) && preg_match('/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})*$/', $value) === 1,
                default => is_string($value),
            };
            if (!$ok) {
                throw $this->error('context_invalid', 400);
            }
        }
        return $context;
    }

    private function validSelection(ActionDefinition $definition, mixed $value): bool
    {
        if (!is_array($value) || !is_string($value['name'] ?? null) || !is_string($value['client_id'] ?? null)) {
            return false;
        }
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $value['client_id']) !== 1) {
            return false;
        }
        $extra = array_diff(array_keys($value), ['name', 'client_id', 'attributes']);
        if ($extra !== []) {
            return false;
        }
        $attributes = $value['attributes'] ?? [];
        if (!is_array($attributes) || ($attributes !== [] && array_is_list($attributes))) {
            return false;
        }
        $allowed = $definition->selectionAttributes();
        foreach ($attributes as $name => $attribute) {
            if (!in_array($name, $allowed, true)) {
                return false;
            }
            if ($attribute !== null && !is_scalar($attribute)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function runRequest(ActionDefinition $definition, int $postId, int $userId, array $context): RunRequest
    {
        $selection = null;
        if (isset($context['selection']) && is_array($context['selection'])) {
            $selection = [
                'name' => (string) $context['selection']['name'],
                'clientId' => (string) $context['selection']['client_id'],
                'attributes' => (array) ($context['selection']['attributes'] ?? []),
            ];
        }
        $language = isset($context['language']) && is_string($context['language'])
            ? $context['language']
            : str_replace('_', '-', (string) get_locale());
        return new RunRequest(
            $definition->id(),
            $postId,
            $userId,
            $context,
            $selection,
            $language,
            Canonical::contextHash($context)
        );
    }

    /**
     * @param array{post_id: int, user_id: int, data: array<string, mixed>} $record
     */
    private function requestFromStored(ActionDefinition $definition, array $record): RunRequest
    {
        $context = $record['data']['context'] ?? [];
        return $this->runRequest($definition, $record['post_id'], $record['user_id'], is_array($context) ? $context : []);
    }

    /**
     * Call a provider callback with the write watch and fatal guard armed.
     *
     * @param array{name: string, clientId: string, attributes: array<string, mixed>}|null $selection
     * @param \Closure(): mixed $call
     * @return array{0: mixed, 1: string, 2: \Throwable|null}
     */
    private function guarded(ActionDefinition $definition, int $postId, int $userId, ?array $selection, \Closure $call): array
    {
        $this->watch->start($definition, $postId, $selection);
        $this->guard->arm($definition->id(), $postId, $userId);
        $value = null;
        $thrown = null;
        try {
            $value = $call();
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $written = $this->watch->stop();
            $this->guard->disarm();
        }
        return [$value, $written, $thrown];
    }

    private function providerThrew(ActionDefinition $definition, int $postId, int $userId, \Throwable $e): never
    {
        self::debugLog($definition->id(), $e);
        $this->violation($definition->id(), 'provider_error', ['class' => get_class($e)]);
        $this->fail($definition->id(), $postId, $userId, 'provider_error', 500, self::describe($e));
    }

    /**
     * @param array{id: string, kind: string, user_id: int, post_id: int, action_id: string, data: array<string, mixed>, context_hash: string, created_at: int, expires_at: int, used: bool}|null $job
     * @return array{status: int, body: array<string, mixed>}
     */
    private function interpret(ActionDefinition $definition, RunRequest $request, mixed $value, string $written, ?array $job): array
    {
        $postId = $request->postId();
        $userId = $request->userId();
        if ($written !== '') {
            $this->violation($definition->id(), 'provider_wrote_during_run', ['target' => $written]);
            $this->fail($definition->id(), $postId, $userId, 'provider_wrote_during_run', 500, $written);
        }
        try {
            $answer = $this->validator->runReturn($value, $definition);
        } catch (ContractViolation $e) {
            $rule = $e->getMessage();
            $this->violation($definition->id(), 'invalid_result', ['rule' => $rule]);
            if (in_array($rule, ['wrong_return_type', 'pending_shape', 'pending_ref'], true)) {
                $this->fail($definition->id(), $postId, $userId, 'provider_error', 500, $rule);
            }
            $this->fail($definition->id(), $postId, $userId, 'invalid_result', 502, $rule);
        }

        if ($answer instanceof \WP_Error) {
            $error = $this->passThrough($answer);
            $this->log->add('run_failed', [
                'action_id' => $definition->id(), 'post_id' => $postId, 'user_id' => $userId,
                'code' => $error->errorCode(),
            ]);
            do_action('editor_ai_sidebar_run_failed', $definition->id(), $postId, $userId, $error->errorCode());
            throw $error;
        }

        if ($answer instanceof Pending) {
            if ($definition->callback('poll') === null) {
                $this->violation($definition->id(), 'pending_without_poll', []);
                $this->fail($definition->id(), $postId, $userId, 'provider_error', 500, 'pending_without_poll');
            }
            return $this->pending($definition, $request, $answer, $job);
        }

        return ['status' => 200, 'body' => $this->issue($definition, $request, $answer)];
    }

    /**
     * @param array{id: string, data: array<string, mixed>}|null $job
     * @return array{status: int, body: array<string, mixed>}
     */
    private function pending(ActionDefinition $definition, RunRequest $request, Pending $pending, ?array $job): array
    {
        if ($job !== null) {
            $data = $job['data'];
            $data['ref'] = $pending->providerRef();
            $data['poll_after'] = $pending->pollAfter();
            $this->store->updateData($job['id'], $data);
            return ['status' => 202, 'body' => ['job_id' => $job['id'], 'poll_after' => $pending->pollAfter()]];
        }
        $jobId = bin2hex(random_bytes(16));
        $now = Clock::now();
        $stored = $this->store->insert(
            $jobId,
            RecordStore::JOB,
            $request->userId(),
            $request->postId(),
            $definition->id(),
            ['ref' => $pending->providerRef(), 'poll_after' => $pending->pollAfter(), 'context' => $request->context()],
            $request->contextHash(),
            $now,
            $now + $definition->maxWait() + 60
        );
        if (!$stored) {
            $this->fail($definition->id(), $request->postId(), $request->userId(), 'storage_error', 500, 'job insert');
        }
        $this->log->add('job_started', [
            'action_id' => $definition->id(), 'post_id' => $request->postId(), 'user_id' => $request->userId(),
        ]);
        return ['status' => 202, 'body' => ['job_id' => $jobId, 'poll_after' => $pending->pollAfter()]];
    }

    /**
     * Store a record for a valid result and build the envelope (case 32).
     *
     * @return array<string, mixed>
     */
    private function issue(ActionDefinition $definition, RunRequest $request, Result $result): array
    {
        $items = array_map(static fn (Item $item): array => $item->toArray(), $result->items());
        $notes = array_map(static fn (Note $note): array => $note->toArray(), $result->notes());
        $envelope = [
            'result_id' => null,
            'action_id' => $definition->id(),
            'post_id' => $request->postId(),
            'layout' => $result->layout(),
            'items' => $items,
            'notes' => $notes,
            'context_hash' => $request->contextHash(),
            'expires_in' => 0,
        ];
        if ($items === []) {
            $this->log->add('result_empty', [
                'action_id' => $definition->id(), 'post_id' => $request->postId(), 'user_id' => $request->userId(),
            ]);
            return $envelope;
        }

        $stored = [];
        foreach ($result->items() as $item) {
            $row = $item->toArray();
            $row['hash'] = Canonical::itemHash($item->type(), $item->value());
            $stored[] = $row;
        }
        $ttl = self::resultTtl();
        $now = Clock::now();
        $resultId = bin2hex(random_bytes(16));
        $ok = $this->store->insert(
            $resultId,
            RecordStore::RESULT,
            $request->userId(),
            $request->postId(),
            $definition->id(),
            ['items' => $stored, 'context' => $request->context(), 'applied' => []],
            $request->contextHash(),
            $now,
            $now + $ttl
        );
        if (!$ok) {
            $this->fail($definition->id(), $request->postId(), $request->userId(), 'storage_error', 500, 'result insert');
        }
        $this->log->add('result_issued', [
            'action_id' => $definition->id(), 'post_id' => $request->postId(), 'user_id' => $request->userId(),
            'result_id' => $resultId,
        ]);
        do_action(
            'editor_ai_sidebar_result_issued',
            $definition->id(),
            $request->postId(),
            $request->userId(),
            $resultId,
            count($items)
        );
        $envelope['result_id'] = $resultId;
        $envelope['expires_in'] = $ttl;
        return $envelope;
    }

    /**
     * Work out the item to apply and whether the user edited it (case 49).
     *
     * @param array<string, mixed> $stored
     * @return array{0: Item, 1: bool}
     */
    private function finalItem(ActionDefinition $definition, array $stored, mixed $value, RunRequest $request): array
    {
        $original = $this->itemFromStored($stored);
        try {
            $hash = is_string($value) || is_array($value) ? Canonical::itemHash($original->type(), $value) : '';
        } catch (\JsonException) {
            $hash = '';
        }
        if ($hash !== '' && hash_equals((string) ($stored['hash'] ?? ''), $hash)) {
            return [$original, false];
        }
        if (!$definition->editable()) {
            throw $this->error('value_mismatch', 422);
        }
        try {
            $clean = $this->validator->value($original->type(), $value, $definition);
        } catch (ContractViolation) {
            throw $this->error('value_invalid', 422);
        }
        $edited = $original->withValue($clean);
        $validate = $definition->callback('validate');
        if ($validate !== null) {
            try {
                $verdict = $validate(
                    $this->wantsArray($validate, 0) ? $edited->toArray() : $edited,
                    $this->wantsArray($validate, 1) ? $request->toArray() : $request
                );
            } catch (\Throwable $e) {
                self::debugLog($definition->id(), $e);
                $this->violation($definition->id(), 'validate_threw', ['class' => get_class($e)]);
                throw $this->error('provider_error', 500);
            }
            if ($verdict instanceof \WP_Error) {
                $error = $this->passThrough($verdict);
                throw new ApiError($error->errorCode(), $error->getMessage(), 422);
            }
        }
        return [$edited, true];
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function itemFromStored(array $stored): Item
    {
        $notes = [];
        foreach ((array) ($stored['notes'] ?? []) as $note) {
            if (is_array($note) && is_string($note['text'] ?? null)) {
                $notes[] = ($note['level'] ?? '') === Note::WARNING ? Note::warning($note['text']) : Note::info($note['text']);
            }
        }
        $value = $stored['value'] ?? '';
        return Item::make(
            (string) ($stored['id'] ?? ''),
            (string) ($stored['type'] ?? Item::TEXT),
            is_array($value) ? $value : (string) $value,
            $notes,
            isset($stored['score']) ? (float) $stored['score'] : null,
            isset($stored['score_label']) ? (string) $stored['score_label'] : null,
            isset($stored['lang']) ? (string) $stored['lang'] : null,
            isset($stored['dir']) ? (string) $stored['dir'] : null
        );
    }

    /**
     * After a successful apply: keep the record usable for other items when
     * the action allows reapply (case 60), else leave it used.
     *
     * @param array{id: string, data: array<string, mixed>} $record
     * @param array<int, mixed> $applied
     */
    private function afterApply(ActionDefinition $definition, array $record, string $itemId, array $applied): void
    {
        if (!$definition->reapply()) {
            return;
        }
        $data = $record['data'];
        $applied[] = $itemId;
        $data['applied'] = array_values(array_unique($applied));
        $this->store->updateData($record['id'], $data);
        $this->store->release($record['id']);
    }

    private function applied(
        ActionDefinition $definition,
        int $postId,
        int $userId,
        string $resultId,
        string $itemId,
        bool $edited,
        string $mode
    ): void {
        $this->log->add('applied', [
            'action_id' => $definition->id(), 'post_id' => $postId, 'user_id' => $userId,
            'result_id' => $resultId, 'code' => $mode . ($edited ? ':edited' : ''),
        ]);
        do_action('editor_ai_sidebar_applied', $definition->id(), $postId, $userId, $resultId, $itemId, $edited, $mode);
    }

    /**
     * New values of server-mode targets the block editor also holds, so the
     * sidebar can update its copy without making the post dirty (case 56).
     *
     * @param string[] $targets
     * @param array{name: string, clientId: string, attributes: array<string, mixed>}|null $selection
     */
    private function syncData(\WP_Post $post, array $targets, ?array $selection): object
    {
        $registered = array_merge(
            get_registered_meta_keys('post', ''),
            get_registered_meta_keys('post', $post->post_type)
        );
        $meta = [];
        $alt = [];
        $sync = [];
        foreach ($targets as $target) {
            if (str_starts_with($target, 'meta:')) {
                $key = substr($target, 5);
                if (!isset($registered[$key]) || empty($registered[$key]['show_in_rest'])) {
                    continue;
                }
                $single = !empty($registered[$key]['single']);
                $value = get_post_meta($post->ID, $key, $single);
                if (is_scalar($value) || (is_array($value) && array_filter($value, 'is_scalar') === $value)) {
                    $meta[$key] = $value;
                }
            } elseif ($target === 'featured_media') {
                $sync['featured_media'] = (int) get_post_thumbnail_id($post->ID);
            } elseif (str_starts_with($target, 'attachment_alt:')) {
                $id = $target === 'attachment_alt:<from selection>'
                    ? (int) ($selection['attributes']['id'] ?? 0)
                    : (int) substr($target, 15);
                if ($id > 0) {
                    $alt[(string) $id] = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
                }
            }
        }
        if ($meta !== []) {
            $sync['meta'] = (object) $meta;
        }
        if ($alt !== []) {
            $sync['attachment_alt'] = (object) $alt;
        }
        return (object) $sync;
    }

    private function passThrough(\WP_Error $error): ApiError
    {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) && is_int($data['status']) ? $data['status'] : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        $code = (string) $error->get_error_code();
        if ($code === '' || preg_match('/^[a-z0-9_-]{1,64}$/i', $code) !== 1) {
            $code = 'provider_error';
        }
        $message = trim(wp_strip_all_tags((string) $error->get_error_message()));
        if ($message === '') {
            $message = Messages::forCode('provider_error');
        }
        return new ApiError($code, mb_substr($message, 0, 300), $status);
    }

    private function fail(string $actionId, int $postId, int $userId, string $code, int $status, string $detail): never
    {
        $this->log->add('run_failed', [
            'action_id' => $actionId, 'post_id' => $postId, 'user_id' => $userId, 'code' => $code, 'detail' => $detail,
        ]);
        do_action('editor_ai_sidebar_run_failed', $actionId, $postId, $userId, $code);
        $message = $code === 'storage_error'
            ? __('The site could not store this result. Try again.', 'editor-ai-sidebar')
            : Messages::forCode($code);
        throw new ApiError($code, $message, $status);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function violation(string $actionId, string $code, array $detail): void
    {
        $this->log->add('contract_violation', [
            'action_id' => $actionId,
            'code' => $code,
            'detail' => (string) wp_json_encode($detail),
        ]);
        do_action('editor_ai_sidebar_contract_violation', $actionId, $code, $detail);
    }

    private function clientIsGone(int $userId, mixed $runToken): bool
    {
        if (is_string($runToken) && preg_match(self::TOKEN_PATTERN, $runToken) === 1) {
            $key = self::cancelKey($userId, $runToken);
            if (get_transient($key) !== false) {
                delete_transient($key);
                return true;
            }
        }
        return ($this->clientGone)();
    }

    private static function cancelKey(int $userId, string $token): string
    {
        return 'editor_ai_sidebar_cx_' . md5($userId . '|' . $token);
    }

    /**
     * Whether a callback's parameter at $index is typed `array`.
     */
    private function wantsArray(callable $callback, int $index): bool
    {
        try {
            if ($callback instanceof \Closure || is_string($callback) && function_exists($callback)) {
                $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));
            } elseif (is_array($callback)) {
                $reflection = new \ReflectionMethod($callback[0], (string) $callback[1]);
            } elseif (is_object($callback)) {
                $reflection = new \ReflectionMethod($callback, '__invoke');
            } else {
                return false;
            }
        } catch (\ReflectionException) {
            return false;
        }
        $params = $reflection->getParameters();
        if (!isset($params[$index])) {
            return false;
        }
        $type = $params[$index]->getType();
        return $type instanceof \ReflectionNamedType && $type->getName() === 'array';
    }

    private static function describe(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage();
    }

    private static function debugLog(string $actionId, \Throwable $e): void
    {
        if (defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG')) {
            // Only with WP_DEBUG_LOG on, and only the action id and class (section 11).
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('editor-ai-sidebar: action %s threw %s', $actionId, get_class($e)));
        }
    }
}
