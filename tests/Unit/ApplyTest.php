<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Unit;

use IndianOpinion\EditorAiSidebar\ApplyOutcome;
use IndianOpinion\EditorAiSidebar\ApplyRequest;
use IndianOpinion\EditorAiSidebar\Clock;
use IndianOpinion\EditorAiSidebar\Item;
use IndianOpinion\EditorAiSidebar\Result;
use IndianOpinion\EditorAiSidebar\RunRequest;
use IndianOpinion\EditorAiSidebar\Tests\Support\TestCase;

/**
 * Approving, applying and dismissing (PRD 4.6 and 4.7).
 */
final class ApplyTest extends TestCase
{
    /** @var ApplyRequest[] */
    private array $applied = [];

    private const CONTEXT = ['title' => 'Old title', 'text' => 'Body.'];

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> The run envelope.
     */
    private function issue(int $post, array $overrides = []): array
    {
        $this->provide($this->definition($overrides));
        $response = $this->runAction((string) ($overrides['id'] ?? 'test/headline'), $post, self::CONTEXT);
        self::assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));
        $body = $response->get_data();
        self::assertIsArray($body);
        return $body;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function server(array $overrides = [], ?\Closure $apply = null): array
    {
        return array_merge([
            'id' => 'test/server',
            'group' => 'meta',
            'apply' => ['mode' => 'server', 'targets' => ['meta:my_description']],
            'apply_callback' => $apply ?? function (ApplyRequest $request): ApplyOutcome {
                $this->applied[] = $request;
                update_post_meta($request->postId(), 'my_description', (string) $request->item()->value());
                return ApplyOutcome::done('Saved the description.', ['meta:my_description']);
            },
        ], $overrides);
    }

    public function testT47NoWriteWithoutApplyAndApprovalRequired(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post, $this->server());
        self::assertSame([], $this->applied);
        self::assertSame([], get_post_meta($post, 'my_description'));

        $this->assertError($this->applyItem($envelope, 'a', ['approved' => null]), 400, 'approval_missing');
        $this->assertError($this->applyItem($envelope, 'a', ['approved' => 'true']), 400, 'approval_missing');
        $this->assertError($this->applyItem($envelope, 'a', ['approved' => 1]), 400, 'approval_missing');
        self::assertSame([], $this->applied);

        self::assertSame(200, $this->applyItem($envelope, 'a')->get_status());
        self::assertCount(1, $this->applied);
        self::assertSame('New headline', get_post_meta($post, 'my_description', true));
    }

    public function testT48EachApplyCheck(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post, $this->server(['apply_capability' => 'publish_posts']));

        // Someone else's result.
        wp_set_current_user(self::OTHER_EDITOR);
        $this->assertError($this->applyItem($envelope, 'a'), 403, 'not_your_result');
        wp_set_current_user(self::EDITOR);

        // Mismatched post or action in the body.
        $this->assertError($this->applyItem($envelope, 'a', ['post_id' => $post + 1]), 400, 'result_mismatch');
        $this->assertError($this->applyItem($envelope, 'a', ['action_id' => 'test/other']), 400, 'result_mismatch');

        // Unknown item.
        $this->assertError($this->applyItem($envelope, 'zzz'), 400, 'item_unknown');

        // Missing apply capability.
        \WpStub::$caps[self::EDITOR]['publish_posts'] = false;
        $this->assertError($this->applyItem($envelope, 'a'), 403, 'rest_forbidden');
        \WpStub::$caps[self::EDITOR]['publish_posts'] = true;

        // Locked by another user (T70).
        \WpStub::$locks[$post] = self::OTHER_EDITOR;
        $this->assertError($this->applyItem($envelope, 'a'), 409, 'post_locked');
        \WpStub::$locks[$post] = self::EDITOR;

        // Works, then used.
        self::assertSame(200, $this->applyItem($envelope, 'a')->get_status());
        $this->assertError($this->applyItem($envelope, 'a'), 409, 'result_used');

        // Expired.
        $second = $this->runAction('test/server', $post, self::CONTEXT)->get_data();
        Clock::advance(1800);
        $this->assertError($this->applyItem($second, 'a'), 410, 'result_expired');

        // Garbage id.
        $this->assertError($this->rest('POST', '/editor-ai-sidebar/v1/results/not-a-real-id/apply', [
            'approved' => true,
        ]), 410, 'result_expired');
        self::assertCount(1, $this->applied);
    }

    public function testT10ActionGoneAfterProviderDeactivation(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post);
        remove_all_filters('editor_ai_sidebar_actions');
        $this->plugin->registry()->reset();
        $this->assertError($this->applyItem($envelope, 'a'), 410, 'action_gone');
    }

    public function testT49ValueChecks(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post);
        $this->assertError($this->applyItem($envelope, 'a', ['value' => 'Tampered']), 422, 'value_mismatch');
        $this->assertError($this->applyItem($envelope, 'a', ['value' => ['array']]), 422, 'value_mismatch');

        remove_all_filters('editor_ai_sidebar_actions');
        $seen = [];
        $editable = $this->issue($post, [
            'id' => 'test/editable',
            'editable' => true,
            'validate' => static function (Item $edited, RunRequest $original) use (&$seen): bool|\WP_Error {
                $seen[] = [$edited->value(), $original->context()['title']];
                return mb_strlen((string) $edited->value()) > 20
                    ? new \WP_Error('too_long', 'Keep it under 20 characters.')
                    : true;
            },
        ]);
        $response = $this->applyItem($editable, 'a', ['value' => 'A much longer edited headline']);
        $this->assertError($response, 422, 'too_long');
        self::assertSame('Keep it under 20 characters.', $response->get_data()['message']);
        $this->assertError($this->applyItem($editable, 'a', ['value' => '   ']), 422, 'value_invalid');

        // The record stays open for more editing.
        $ok = $this->applyItem($editable, 'a', ['value' => 'Short edit']);
        self::assertSame(200, $ok->get_status());
        self::assertSame('Short edit', $ok->get_data()['value']);
        self::assertSame([['A much longer edited headline', 'Old title'], ['Short edit', 'Old title']], $seen);
        $applied = $this->firedOf('editor_ai_sidebar_applied');
        self::assertTrue($applied[0][1][5], 'edited flag');
    }

    public function testT50ContextChanged(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post);
        $stale = hash('sha256', '{"text":"Body.","title":"Edited since"}');
        $this->assertError($this->applyItem($envelope, 'a', ['context_hash' => $stale]), 409, 'context_changed');
        $this->assertError($this->applyItem($envelope, 'a', ['context_hash' => null]), 409, 'context_changed');
        $ok = $this->applyItem($envelope, 'a', ['context_hash' => $stale, 'confirm_stale' => true]);
        self::assertSame(200, $ok->get_status());
    }

    public function testT51EditorModeWritesNothingAndFiresApplied(): void
    {
        $post = $this->post('post', 'draft', ['post_title' => 'Old title']);
        $envelope = $this->issue($post);
        $response = $this->applyItem($envelope, 'a');
        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertSame('editor', $body['mode']);
        self::assertSame('set_title', $body['operation']);
        self::assertSame('New headline', $body['value']);
        self::assertSame(['title'], $body['targets']);
        self::assertSame('Old title', get_post($post)?->post_title, 'the server writes nothing');
        self::assertSame([], \WpStub::$meta[$post] ?? []);
        $applied = $this->firedOf('editor_ai_sidebar_applied');
        self::assertSame(['test/headline', $post, self::EDITOR, $envelope['result_id'], 'a', false, 'editor'], $applied[0][1]);
        self::assertTrue($this->store->find($envelope['result_id'], 'result')['used'] ?? false);
        self::assertContains('applied:editor', $this->loggedEvents());
    }

    public function testT55ServerModeCallsApplyOnceAndReturnsOutcome(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post, $this->server(['apply_callback' => function (ApplyRequest $request): ApplyOutcome {
            $this->applied[] = $request;
            return ApplyOutcome::done('Done <b>well</b>.', ['provider'], [
                ['label' => 'Open the report', 'url' => admin_url('admin.php?page=report')],
                ['label' => 'Elsewhere', 'url' => 'https://elsewhere.example/x'],
            ]);
        }, 'apply' => ['mode' => 'server', 'targets' => ['provider']]]));
        $response = $this->applyItem($envelope, 'a');
        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertSame('server', $body['mode']);
        self::assertSame('Done well.', $body['message']);
        self::assertSame([['label' => 'Open the report', 'url' => 'https://example.test/wp-admin/admin.php?page=report']], $body['links']);
        self::assertCount(1, $this->applied);
        self::assertSame($envelope['result_id'], $this->applied[0]->resultId());
        self::assertFalse($this->applied[0]->edited());
        self::assertContains('contract_violation:foreign_link', $this->loggedEvents());
    }

    public function testT56ServerApplyReturnsSyncForRestMeta(): void
    {
        register_post_meta('post', 'my_description', ['show_in_rest' => true, 'single' => true]);
        $post = $this->post();
        $envelope = $this->issue($post, $this->server());
        $body = $this->applyItem($envelope, 'a')->get_data();
        self::assertEquals((object) ['meta' => (object) ['my_description' => 'New headline']], $body['sync']);
    }

    public function testT57WpErrorKeepsRecordUnlessFinal(): void
    {
        $post = $this->post();
        $attempts = 0;
        $envelope = $this->issue($post, $this->server(['apply_callback' => static function () use (&$attempts): \WP_Error|ApplyOutcome {
            $attempts++;
            if ($attempts === 1) {
                return new \WP_Error('busy', 'Try again in a minute.', ['status' => 503]);
            }
            if ($attempts === 2) {
                return new \WP_Error('refused', 'Refused for good.', ['status' => 422, 'final' => true]);
            }
            return ApplyOutcome::done('ok', []);
        }]));
        $this->assertError($this->applyItem($envelope, 'a'), 503, 'busy');
        $this->assertError($this->applyItem($envelope, 'a'), 422, 'refused');
        $this->assertError($this->applyItem($envelope, 'a'), 409, 'result_used');
        self::assertSame(2, $attempts);

        // An exception leaves the record unused too.
        remove_all_filters('editor_ai_sidebar_actions');
        $throws = true;
        $second = $this->issue($post, $this->server(['apply_callback' => static function () use (&$throws): ApplyOutcome {
            if ($throws) {
                throw new \RuntimeException('database away');
            }
            return ApplyOutcome::done('ok', []);
        }]));
        $this->assertError($this->applyItem($second, 'a'), 500, 'provider_error');
        $throws = false;
        self::assertSame(200, $this->applyItem($second, 'a')->get_status());
    }

    public function testT59RunWithoutApplyCapability(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post, ['apply_capability' => 'publish_posts']);
        wp_set_current_user(self::AUTHOR);
        $envelope = $this->runAction('test/headline', $post, self::CONTEXT)->get_data();
        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $post)->get_data();
        self::assertTrue($list[0]['available']);
        self::assertFalse($list[0]['can_apply']);
        $this->assertError($this->applyItem($envelope, 'a'), 403, 'rest_forbidden');
    }

    public function testRulingEightOnlyTheRunnerApproves(): void
    {
        $post = $this->post();
        wp_set_current_user(self::AUTHOR);
        $envelope = $this->issue($post);
        wp_set_current_user(self::ADMIN);
        $this->assertError($this->applyItem($envelope, 'a'), 403, 'not_your_result');
    }

    public function testT60ChoicesApplyOnceUnlessReapply(): void
    {
        $post = $this->post();
        $choices = static fn (): Result => Result::choices([Item::text('a', 'One'), Item::text('b', 'Two')]);
        $envelope = $this->issue($post, ['result' => ['layout' => 'choices', 'item_type' => 'text'], 'run' => $choices]);
        self::assertSame(200, $this->applyItem($envelope, 'a')->get_status());
        $this->assertError($this->applyItem($envelope, 'b'), 409, 'result_used');

        remove_all_filters('editor_ai_sidebar_actions');
        $again = $this->issue($post, [
            'id' => 'test/reapply',
            'result' => ['layout' => 'choices', 'item_type' => 'text'],
            'apply' => ['mode' => 'editor', 'operation' => 'set_title', 'reapply' => true],
            'run' => $choices,
        ]);
        self::assertSame(200, $this->applyItem($again, 'a')->get_status());
        self::assertSame(200, $this->applyItem($again, 'b')->get_status());
        $this->assertError($this->applyItem($again, 'a'), 409, 'result_used');
        self::assertCount(3, $this->firedOf('editor_ai_sidebar_applied'), 'each apply is logged');
    }

    public function testT61StatusChangeIsAContractViolation(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post, $this->server(['apply_callback' => static function (ApplyRequest $request): ApplyOutcome {
            wp_update_post(['ID' => $request->postId(), 'post_status' => 'publish']);
            return ApplyOutcome::done('Published it, oops.', ['status']);
        }]));
        self::assertSame(200, $this->applyItem($envelope, 'a')->get_status());
        $violations = $this->firedOf('editor_ai_sidebar_contract_violation');
        self::assertSame('status_changed', $violations[0][1][1]);
        self::assertSame('draft', $violations[0][1][2]['before']);
        self::assertSame('publish', $violations[0][1][2]['after']);
    }

    public function testT73LongItemAppliesInFull(): void
    {
        $post = $this->post();
        $long = str_repeat('पाठ ', 1200);
        $envelope = $this->issue($post, [
            'apply' => ['mode' => 'editor', 'operation' => 'set_excerpt'],
            'run' => static fn (): Result => Result::single(Item::text('a', $long)),
        ]);
        $body = $this->applyItem($envelope, 'a')->get_data();
        self::assertSame(trim($long), $body['value']);
    }

    public function testT78RecordEvictedEarlyGivesExpiredAndNoWrite(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post, $this->server());
        $this->store->delete($envelope['result_id']);
        $this->assertError($this->applyItem($envelope, 'a'), 410, 'result_expired');
        self::assertSame([], $this->applied);
    }

    public function testT68TrashedPostApplyIs404(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post);
        \WpStub::$posts[$post]->post_status = 'trash';
        $this->assertError($this->applyItem($envelope, 'a'), 404, 'post_missing');
    }

    public function testT46DismissMarksUsedCallsDismissAndWritesNothing(): void
    {
        $post = $this->post();
        $dismissed = [];
        $envelope = $this->issue($post, $this->server([
            'dismiss' => static function (string $resultId, array $items, int $userId) use (&$dismissed): void {
                $dismissed[] = [$resultId, $items[0] instanceof Item ? $items[0]->value() : null, $userId];
            },
        ]));
        $response = $this->rest('POST', '/editor-ai-sidebar/v1/results/' . $envelope['result_id'] . '/dismiss', [
            'post_id' => $post,
            'action_id' => 'test/server',
        ]);
        self::assertSame(204, $response->get_status());
        self::assertSame([[$envelope['result_id'], 'New headline', self::EDITOR]], $dismissed);
        self::assertSame([], $this->applied);
        self::assertSame([], get_post_meta($post, 'my_description'));
        $this->assertError($this->applyItem($envelope, 'a'), 409, 'result_used');
        self::assertCount(1, $this->firedOf('editor_ai_sidebar_dismissed'));
    }

    public function testNoActionHookCarriesItemText(): void
    {
        $post = $this->post();
        $envelope = $this->issue($post, $this->server());
        $this->applyItem($envelope, 'a');
        self::assertStringNotContainsString('New headline', (string) json_encode($this->fired));
        self::assertStringNotContainsString('New headline', (string) json_encode($this->plugin->log()->all()));
        self::assertStringNotContainsString('Old title', (string) json_encode($this->plugin->log()->all()));
    }
}
