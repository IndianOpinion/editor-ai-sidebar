<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Unit;

use IndianOpinion\EditorAiSidebar\Canonical;
use IndianOpinion\EditorAiSidebar\Clock;
use IndianOpinion\EditorAiSidebar\Item;
use IndianOpinion\EditorAiSidebar\Note;
use IndianOpinion\EditorAiSidebar\Pending;
use IndianOpinion\EditorAiSidebar\Result;
use IndianOpinion\EditorAiSidebar\RunRequest;
use IndianOpinion\EditorAiSidebar\Tests\Support\TestCase;

/**
 * Running an action (PRD 4.3 to 4.5).
 */
final class RunTest extends TestCase
{
    private int $calls = 0;

    /** @var RunRequest|array<string, mixed>|null */
    private RunRequest|array|null $received = null;

    /**
     * @param array<string, mixed> $overrides
     */
    private function counting(array $overrides = [], ?\Closure $answer = null): void
    {
        $this->provide($this->definition(array_merge([
            'run' => function (RunRequest $request) use ($answer): mixed {
                $this->calls++;
                $this->received = $request;
                return $answer !== null ? $answer($request) : Result::single(Item::text('a', 'New headline'));
            },
        ], $overrides)));
    }

    /**
     * @return array<string, string>
     */
    private static function context(): array
    {
        return ['title' => 'Unsaved title', 'text' => 'Some body text.'];
    }

    public function testT21OnlyListedSelectionAttributesReachTheProvider(): void
    {
        $this->counting([
            'selection' => ['core/image'],
            'needs' => ['title', 'selection_attributes' => ['id', 'alt']],
            'apply' => ['mode' => 'editor', 'operation' => 'set_block_attribute', 'attribute' => 'alt'],
        ]);
        $post = $this->post();
        $context = [
            'title' => 'T',
            'selection' => ['name' => 'core/image', 'client_id' => 'abc-1', 'attributes' => ['id' => 7, 'alt' => '']],
        ];
        $response = $this->runAction('test/headline', $post, $context);
        self::assertSame(200, $response->get_status());
        self::assertInstanceOf(RunRequest::class, $this->received);
        self::assertSame(
            ['name' => 'core/image', 'clientId' => 'abc-1', 'attributes' => ['id' => 7, 'alt' => '']],
            $this->received->selection()
        );

        // An attribute that was not declared is refused before the provider runs.
        $context['selection']['attributes']['caption'] = 'secret';
        $this->assertError($this->runAction('test/headline', $post, $context), 400, 'context_invalid');
        self::assertSame(1, $this->calls);
    }

    public function testT20SelectionRequiredServerSide(): void
    {
        $this->counting([
            'selection' => ['core/image'],
            'needs' => ['title', 'selection_attributes' => ['id']],
            'apply' => ['mode' => 'editor', 'operation' => 'set_block_attribute', 'attribute' => 'alt'],
        ]);
        $post = $this->post();
        $this->assertError($this->runAction('test/headline', $post, ['title' => 'T']), 400, 'selection_required');
        $wrong = ['title' => 'T', 'selection' => ['name' => 'core/paragraph', 'client_id' => 'x1', 'attributes' => []]];
        $this->assertError($this->runAction('test/headline', $post, $wrong), 400, 'selection_required');
        self::assertSame(0, $this->calls);
    }

    public function testT24ContextCarriesOnlyDeclaredNeeds(): void
    {
        $this->counting();
        $post = $this->post('post', 'draft', ['post_title' => 'Saved title']);
        $response = $this->runAction('test/headline', $post, self::context());
        self::assertSame(200, $response->get_status());
        self::assertInstanceOf(RunRequest::class, $this->received);
        self::assertSame(self::context(), $this->received->context(), 'the unsaved title reaches the provider');
        self::assertSame('en-GB', $this->received->language());

        $extra = self::context() + ['content' => '<!-- wp:paragraph -->x<!-- /wp:paragraph -->'];
        $this->assertError($this->runAction('test/headline', $post, $extra), 400, 'context_invalid');
        $missing = ['title' => 'Only a title'];
        $this->assertError($this->runAction('test/headline', $post, $missing), 400, 'context_invalid');
        $wrongType = ['title' => ['not' => 'a string'], 'text' => 'x'];
        $this->assertError($this->runAction('test/headline', $post, $wrongType), 400, 'context_invalid');
        self::assertSame(1, $this->calls);
    }

    public function testT25ContextOverTheLimitIs413(): void
    {
        $this->counting();
        add_filter('editor_ai_sidebar_context_limit', static fn (): int => 2048);
        $post = $this->post();
        $big = ['title' => 'T', 'text' => str_repeat('x', 2100)];
        $this->assertError($this->runAction('test/headline', $post, $big), 413, 'context_too_large');
        self::assertSame(0, $this->calls);
    }

    public function testT26SecondConcurrentRunIsRefusedAndTheLockClears(): void
    {
        $post = $this->post();
        $inner = null;
        $this->counting([], function () use ($post, &$inner): Result {
            if ($inner === null) {
                $inner = $this->runAction('test/headline', $post, self::context());
            }
            return Result::single(Item::text('a', 'x'));
        });
        $outer = $this->runAction('test/headline', $post, self::context());
        self::assertSame(200, $outer->get_status());
        self::assertInstanceOf(\WP_REST_Response::class, $inner);
        $this->assertError($inner, 409, 'run_in_progress');

        // The lock is released when the run ends.
        self::assertSame(200, $this->runAction('test/headline', $post, self::context())->get_status());

        // A lock left behind by a dead request expires after timeout + 30 s.
        $this->store->acquireLock(
            'lock-' . md5(self::EDITOR . '|' . $post . '|test/headline'),
            self::EDITOR,
            $post,
            'test/headline',
            Clock::now(),
            Clock::now() + 90
        );
        $this->assertError($this->runAction('test/headline', $post, self::context()), 409, 'run_in_progress');
        Clock::advance(91);
        self::assertSame(200, $this->runAction('test/headline', $post, self::context())->get_status());
    }

    public function testT27EachFailedCheckReturnsItsCodeAndRunIsNeverCalled(): void
    {
        $post = $this->post();
        $this->counting([
            'run_capability' => 'publish_posts',
            'available' => static fn (int $p, int $u): bool|string => get_option('closed') ? 'Closed today.' : true,
        ]);

        // Sidebar off.
        update_option('editor_ai_sidebar_settings', ['enabled' => false]);
        $this->assertError($this->runAction('test/headline', $post, self::context()), 503, 'sidebar_disabled');
        delete_option('editor_ai_sidebar_settings');

        // Logged out.
        wp_set_current_user(0);
        $this->assertError($this->runAction('test/headline', $post, self::context()), 401, 'rest_forbidden');
        wp_set_current_user(self::EDITOR);

        // Post missing, then no edit_post.
        $this->assertError($this->runAction('test/headline', 99999, self::context()), 404, 'post_missing');
        \WpStub::$caps[self::EDITOR]['edit_post'] = static fn (int $id): bool => $id !== $post;
        $this->assertError($this->runAction('test/headline', $post, self::context()), 403, 'rest_forbidden');
        \WpStub::$caps[self::EDITOR]['edit_post'] = true;

        // Unknown action, then a disabled one.
        $this->assertError($this->runAction('test/nothing', $post, self::context()), 404, 'action_unknown');
        update_option('editor_ai_sidebar_settings', ['enabled' => true, 'disabled' => ['test/headline']]);
        $this->assertError($this->runAction('test/headline', $post, self::context()), 403, 'action_disabled');
        delete_option('editor_ai_sidebar_settings');

        // Run capability.
        wp_set_current_user(self::AUTHOR);
        $this->assertError($this->runAction('test/headline', $post, self::context()), 403, 'rest_forbidden');
        wp_set_current_user(self::EDITOR);

        // Context shape.
        $this->assertError($this->runAction('test/headline', $post, ['nope' => 1]), 400, 'context_invalid');
        $this->assertError($this->runAction('test/headline', $post, ['a', 'b']), 400, 'context_invalid');

        // available() says no (T18).
        update_option('closed', true);
        $response = $this->runAction('test/headline', $post, self::context());
        $this->assertError($response, 409, 'action_unavailable');
        self::assertSame('Closed today.', $response->get_data()['message']);
        delete_option('closed');

        self::assertSame(0, $this->calls);
        self::assertSame(200, $this->runAction('test/headline', $post, self::context())->get_status());
        self::assertSame(1, $this->calls);
    }

    public function testT28RateLimitWithRetryAfter(): void
    {
        $this->counting();
        $post = $this->post();
        for ($i = 0; $i < 30; $i++) {
            self::assertSame(200, $this->runAction('test/headline', $post, self::context())->get_status());
            Clock::advance(10);
        }
        $response = $this->runAction('test/headline', $post, self::context());
        $this->assertError($response, 429, 'rate_limited');
        self::assertSame(300, $response->get_data()['data']['retry_after']);
        self::assertSame('300', $response->get_headers()['Retry-After']);
        self::assertSame(30, $this->calls);
        Clock::advance(300);
        self::assertSame(200, $this->runAction('test/headline', $post, self::context())->get_status());
    }

    public function testDailyCapPerAction(): void
    {
        $this->counting(['daily_cap' => 2]);
        add_filter('editor_ai_sidebar_rate_limit', static fn (): array => ['runs' => 100, 'window' => 600]);
        $post = $this->post();
        self::assertSame(200, $this->runAction('test/headline', $post, self::context())->get_status());
        self::assertSame(200, $this->runAction('test/headline', $post, self::context())->get_status());
        $this->assertError($this->runAction('test/headline', $post, self::context()), 429, 'daily_cap_reached');
        self::assertSame(2, $this->calls);

        // The site can raise it per action.
        add_filter('editor_ai_sidebar_daily_cap', static fn (int $cap, string $id): int => $id === 'test/headline' ? 3 : $cap, 10, 2);
        self::assertSame(200, $this->runAction('test/headline', $post, self::context())->get_status());

        // A new UTC day starts a new count.
        remove_all_filters('editor_ai_sidebar_daily_cap');
        Clock::advance(86400);
        self::assertSame(200, $this->runAction('test/headline', $post, self::context())->get_status());
    }

    public function testT29WrongReturnTypeIsProviderError(): void
    {
        $this->counting([], static fn (): string => 'just a string');
        $response = $this->runAction('test/headline', $this->post(), self::context());
        $this->assertError($response, 500, 'provider_error');
        self::assertCount(1, $this->firedOf('editor_ai_sidebar_contract_violation'));
        self::assertSame([], $this->store->results());
    }

    public function testT30WriteToDeclaredTargetDiscardsResult(): void
    {
        $post = $this->post();
        $this->provide($this->definition([
            'id' => 'test/meta',
            'group' => 'meta',
            'apply' => ['mode' => 'server', 'targets' => ['meta:my_description']],
            'apply_callback' => static fn (): array => ['message' => 'ok', 'targets' => ['meta:my_description']],
            'run' => static function (RunRequest $request): Result {
                update_post_meta($request->postId(), 'my_private_cache', 'fine');
                update_post_meta($request->postId(), 'my_description', 'too early');
                return Result::single(Item::text('a', 'x'));
            },
        ]), $this->definition([
            'id' => 'test/private',
            'run' => static function (RunRequest $request): Result {
                update_post_meta($request->postId(), 'my_private_cache', 'fine');
                return Result::single(Item::text('a', 'x'));
            },
        ]), $this->definition([
            'id' => 'test/title',
            'run' => static function (RunRequest $request): Result {
                wp_update_post(['ID' => $request->postId(), 'post_title' => 'Sneaky']);
                return Result::single(Item::text('a', 'x'));
            },
        ]));
        $this->assertError($this->runAction('test/meta', $post, self::context()), 500, 'provider_wrote_during_run');
        self::assertSame(200, $this->runAction('test/private', $post, self::context())->get_status());
        $this->assertError($this->runAction('test/title', $post, self::context()), 500, 'provider_wrote_during_run');
        self::assertCount(1, $this->store->results(), 'only the private write produced a record');
        self::assertContains('contract_violation:provider_wrote_during_run', $this->loggedEvents());
    }

    public function testT31InvalidResultsAre502(): void
    {
        $post = $this->post();
        $items = array_map(static fn (int $i): Item => Item::text('i' . $i, 'Headline ' . $i), range(1, 21));
        $cases = [
            'too many' => Result::choices($items),
            'empty value' => Result::single(Item::text('a', '   ')),
            'unknown type' => ['layout' => 'single', 'items' => [['id' => 'a', 'type' => 'html', 'value' => 'x']]],
            'bad id' => Result::single(Item::text('Not Valid', 'x')),
            'duplicate ids' => Result::choices([Item::text('a', 'x'), Item::text('a', 'y')]),
            'one choice' => Result::choices([Item::text('a', 'x')]),
            'wrong item type' => Result::single(Item::list('a', ['x'])),
            'text too long' => Result::single(Item::text('a', str_repeat('x', 20001))),
            'bad utf8' => Result::single(Item::text('a', "\xff\xfe")),
            'bad note level' => ['layout' => 'single', 'items' => [['id' => 'a', 'type' => 'text', 'value' => 'x', 'notes' => [['level' => 'loud', 'text' => 'x']]]]],
            'bad dir' => ['layout' => 'single', 'items' => [['id' => 'a', 'type' => 'text', 'value' => 'x', 'dir' => 'up']]],
        ];
        foreach ($cases as $name => $answer) {
            $layout = in_array($name, ['too many', 'duplicate ids', 'one choice'], true) ? 'choices' : 'single';
            $this->provide($this->definition([
                'id' => 'test/case',
                'result' => ['layout' => $layout, 'item_type' => 'text'],
                'run' => static fn (): mixed => $answer,
            ]));
            $this->assertError($this->runAction('test/case', $post, self::context()), 502, 'invalid_result');
            remove_all_filters('editor_ai_sidebar_actions');
        }
        self::assertSame([], $this->store->results());
    }

    public function testT32ValidResultStoresRecordWithHashes(): void
    {
        $this->counting([], static fn (): Result => Result::single(
            Item::text('a', '  Trimmed headline  ')->withNote(Note::info('A note'))->withLanguage('hi', 'ltr'),
            [Note::warning('Check facts')]
        ));
        $post = $this->post();
        $response = $this->runAction('test/headline', $post, self::context());
        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $body['result_id']);
        self::assertSame(1800, $body['expires_in']);
        self::assertSame(Canonical::contextHash(self::context()), $body['context_hash']);
        self::assertSame('Trimmed headline', $body['items'][0]['value']);
        self::assertSame('hi', $body['items'][0]['lang']);
        self::assertSame([['level' => 'warning', 'text' => 'Check facts']], $body['notes']);

        $record = $this->store->find($body['result_id'], 'result');
        self::assertNotNull($record);
        self::assertSame(self::EDITOR, $record['user_id']);
        self::assertSame($post, $record['post_id']);
        self::assertSame('test/headline', $record['action_id']);
        self::assertFalse($record['used']);
        self::assertSame(Clock::now() + 1800, $record['expires_at']);
        self::assertSame(Canonical::itemHash('text', 'Trimmed headline'), $record['data']['items'][0]['hash']);

        $issued = $this->firedOf('editor_ai_sidebar_result_issued');
        self::assertSame(['test/headline', $post, self::EDITOR, $body['result_id'], 1], $issued[0][1]);
        self::assertCount(1, $this->firedOf('editor_ai_sidebar_run_started'));
    }

    public function testResultTtlIsFilterableAndClamped(): void
    {
        $this->counting();
        add_filter('editor_ai_sidebar_result_ttl', static fn (): int => 5);
        $body = $this->runAction('test/headline', $this->post(), self::context())->get_data();
        self::assertSame(60, $body['expires_in']);
    }

    public function testT33PendingGives202AndHidesTheProviderReference(): void
    {
        $post = $this->post();
        $polls = 0;
        $this->provide($this->definition([
            'run' => static fn (): Pending => Pending::job('secret-provider-ref-123', 4),
            'poll' => static function (string $ref, RunRequest $request) use (&$polls): Pending|Result {
                $polls++;
                return $polls < 2 ? Pending::job($ref, 6) : Result::single(Item::text('a', 'Done: ' . $request->context()['title']));
            },
        ]));
        $start = $this->runAction('test/headline', $post, self::context());
        self::assertSame(202, $start->get_status());
        $body = $start->get_data();
        self::assertSame(4, $body['poll_after']);
        self::assertStringNotContainsString('secret-provider-ref', (string) json_encode($body));

        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $post);
        self::assertStringNotContainsString('secret-provider-ref', (string) json_encode($list->get_data()));

        $first = $this->rest('GET', '/editor-ai-sidebar/v1/jobs/' . $body['job_id']);
        self::assertSame(202, $first->get_status());
        self::assertSame(['job_id' => $body['job_id'], 'poll_after' => 6], $first->get_data());
        self::assertStringNotContainsString('secret-provider-ref', (string) json_encode($first->get_data()));

        $second = $this->rest('GET', '/editor-ai-sidebar/v1/jobs/' . $body['job_id']);
        self::assertSame(200, $second->get_status());
        self::assertSame('Done: Unsaved title', $second->get_data()['items'][0]['value']);
        self::assertNotNull($this->store->find($second->get_data()['result_id'], 'result'));

        // The job is gone once it finished.
        $this->assertError($this->rest('GET', '/editor-ai-sidebar/v1/jobs/' . $body['job_id']), 404, 'job_gone');
    }

    public function testPendingWithoutPollIsProviderError(): void
    {
        $this->counting([], static fn (): Pending => Pending::job('ref'));
        $this->assertError($this->runAction('test/headline', $this->post(), self::context()), 500, 'provider_error');
    }

    public function testJobsBelongToTheirUser(): void
    {
        $post = $this->post();
        $this->provide($this->definition([
            'run' => static fn (): Pending => Pending::job('ref'),
            'poll' => static fn (): Pending => Pending::job('ref'),
        ]));
        $job = $this->runAction('test/headline', $post, self::context())->get_data()['job_id'];
        wp_set_current_user(self::OTHER_EDITOR);
        $this->assertError($this->rest('GET', '/editor-ai-sidebar/v1/jobs/' . $job), 404, 'job_gone');
    }

    public function testRulingOnePendingJobIsListedForResume(): void
    {
        $post = $this->post();
        $this->provide($this->definition([
            'run' => static fn (): Pending => Pending::job('ref', 5),
            'poll' => static fn (): Pending => Pending::job('ref', 5),
        ]));
        $job = $this->runAction('test/headline', $post, self::context())->get_data()['job_id'];

        // A second run while the job is open is refused.
        $this->assertError($this->runAction('test/headline', $post, self::context()), 409, 'run_in_progress');

        Clock::advance(40);
        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $post)->get_data();
        self::assertSame(['job_id' => $job, 'poll_after' => 5, 'elapsed' => 40, 'client_id' => null], $list[0]['pending_job']);

        // Another user does not see it.
        wp_set_current_user(self::OTHER_EDITOR);
        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $post)->get_data();
        self::assertNull($list[0]['pending_job']);
        wp_set_current_user(self::EDITOR);

        // Not listed once the result lifetime has passed, even if the job lives on.
        add_filter('editor_ai_sidebar_result_ttl', static fn (): int => 60);
        Clock::advance(21);
        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $post)->get_data();
        self::assertNull($list[0]['pending_job']);
    }

    public function testT35CancelSyncRunIssuesNoResultAndCancelCallsProvider(): void
    {
        $post = $this->post();
        $token = str_repeat('ab', 16);
        $this->counting([], function () use ($token): Result {
            // The browser aborts and sends DELETE /jobs/<run token> while we work.
            $this->rest('DELETE', '/editor-ai-sidebar/v1/jobs/' . $token);
            return Result::single(Item::text('a', 'x'));
        });
        $response = $this->runAction('test/headline', $post, self::context(), ['run_token' => $token]);
        $this->assertError($response, 409, 'run_cancelled');
        self::assertSame([], $this->store->results());

        // Without the marker the same run issues a result.
        $this->plugin->service()->setClientGoneCheck(static fn (): bool => true);
        $this->provide($this->definition(['id' => 'test/plain']));
        $this->assertError($this->runAction('test/plain', $post, self::context()), 409, 'run_cancelled');
        $this->plugin->service()->setClientGoneCheck(static fn (): bool => false);

        // Queued: DELETE calls the provider's cancel and removes the job.
        $cancelled = [];
        $this->provide($this->definition([
            'id' => 'test/queued',
            'run' => static fn (): Pending => Pending::job('provider-ref-9'),
            'poll' => static fn (): Pending => Pending::job('provider-ref-9'),
            'cancel' => static function (string $ref) use (&$cancelled): void {
                $cancelled[] = $ref;
            },
        ]));
        $job = $this->runAction('test/queued', $post, self::context())->get_data()['job_id'];
        $delete = $this->rest('DELETE', '/editor-ai-sidebar/v1/jobs/' . $job);
        self::assertSame(204, $delete->get_status());
        self::assertSame(['provider-ref-9'], $cancelled);
        $this->assertError($this->rest('GET', '/editor-ai-sidebar/v1/jobs/' . $job), 404, 'job_gone');
    }

    public function testT36JobRecordExpiresAfterMaxWaitPlusSixty(): void
    {
        $post = $this->post();
        $this->provide($this->definition([
            'max_wait' => 120,
            'run' => static fn (): Pending => Pending::job('ref'),
            'poll' => static fn (): Pending => Pending::job('ref'),
        ]));
        $job = $this->runAction('test/headline', $post, self::context())->get_data()['job_id'];
        $record = $this->store->find($job, 'job');
        self::assertNotNull($record);
        self::assertSame(Clock::now() + 180, $record['expires_at']);
        Clock::advance(179);
        self::assertSame(202, $this->rest('GET', '/editor-ai-sidebar/v1/jobs/' . $job)->get_status());
        Clock::advance(1);
        $this->assertError($this->rest('GET', '/editor-ai-sidebar/v1/jobs/' . $job), 404, 'job_gone');
    }

    public function testT37ExceptionGivesGenericMessageAndLogsDetails(): void
    {
        $this->counting([], static function (): never {
            throw new \DomainException('model said no at https://provider.example/v1 with key sk-test');
        });
        $post = $this->post();
        $response = $this->runAction('test/headline', $post, self::context());
        $this->assertError($response, 500, 'provider_error');
        self::assertSame('This action failed. The site log has details.', $response->get_data()['message']);
        self::assertStringNotContainsString('provider.example', (string) json_encode($response->get_data()));
        $failed = array_values(array_filter($this->plugin->log()->all(), static fn (array $e): bool => $e['event'] === 'run_failed'));
        self::assertSame('provider_error', $failed[0]['code']);
        self::assertStringContainsString('DomainException: model said no', $failed[0]['detail']);
        self::assertSame(['test/headline', $post, self::EDITOR, 'provider_error'], $this->firedOf('editor_ai_sidebar_run_failed')[0][1]);
    }

    public function testT38ProviderWpErrorPassesThroughAsText(): void
    {
        $this->counting([], static fn (): \WP_Error => new \WP_Error(
            'daily_cap',
            '<b>Daily cap</b> reached. Try again tomorrow.',
            ['status' => 429]
        ));
        $response = $this->runAction('test/headline', $this->post(), self::context());
        $this->assertError($response, 429, 'daily_cap');
        self::assertSame('Daily cap reached. Try again tomorrow.', $response->get_data()['message']);

        remove_all_filters('editor_ai_sidebar_actions');
        $this->counting([], static fn (): \WP_Error => new \WP_Error('odd', str_repeat('y', 400), ['status' => 302]));
        Clock::advance(1);
        $response = $this->runAction('test/headline', $this->post(), self::context());
        $this->assertError($response, 500, 'odd');
        self::assertSame(300, mb_strlen($response->get_data()['message']));
    }

    public function testT68TrashedPostIs404(): void
    {
        $this->counting();
        $post = $this->post('post', 'trash');
        $this->assertError($this->runAction('test/headline', $post, self::context()), 404, 'post_missing');
    }

    public function testT69LoggedOutIs401EverywhereAndT79HeadersOnEveryRoute(): void
    {
        $this->counting();
        $post = $this->post();
        wp_set_current_user(0);
        $routes = [
            ['GET', '/editor-ai-sidebar/v1/actions?post_id=' . $post, []],
            ['POST', '/editor-ai-sidebar/v1/actions/test/headline/run', ['post_id' => $post]],
            ['GET', '/editor-ai-sidebar/v1/jobs/' . str_repeat('a', 32), []],
            ['DELETE', '/editor-ai-sidebar/v1/jobs/' . str_repeat('a', 32), []],
            ['POST', '/editor-ai-sidebar/v1/results/' . str_repeat('a', 32) . '/apply', []],
            ['POST', '/editor-ai-sidebar/v1/results/' . str_repeat('a', 32) . '/dismiss', []],
        ];
        foreach ($routes as [$method, $path, $params]) {
            $response = $this->rest($method, $path, $params);
            $this->assertError($response, 401, 'rest_forbidden');
            self::assertSame('no-store', $response->get_headers()['Cache-Control'] ?? null, $path);
            self::assertSame('1', $response->get_headers()['X-Editor-AI-Sidebar-Contract'] ?? null, $path);
        }
        wp_set_current_user(self::EDITOR);
        $ok = $this->runAction('test/headline', $post, self::context());
        self::assertSame('no-store', $ok->get_headers()['Cache-Control']);
    }

    public function testT75ZeroItemsStoresNoRecord(): void
    {
        $this->counting([], static fn (): Result => Result::none([Note::info('Nothing to suggest for a post this short.')]));
        $response = $this->runAction('test/headline', $this->post(), self::context());
        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertNull($body['result_id']);
        self::assertSame([], $body['items']);
        self::assertSame('Nothing to suggest for a post this short.', $body['notes'][0]['text']);
        self::assertSame([], $this->store->results());
    }

    public function testArrayFormProvidersWork(): void
    {
        $this->provide($this->definition([
            'run' => static function (array $request): array {
                return [
                    'layout' => 'single',
                    'items' => [['id' => 'a', 'type' => 'text', 'value' => 'From ' . $request['context']['title'], 'score' => 1]],
                ];
            },
        ]));
        $body = $this->runAction('test/headline', $this->post(), self::context())->get_data();
        self::assertSame('From Unsaved title', $body['items'][0]['value']);
        self::assertSame(1.0, $body['items'][0]['score']);
    }
}
