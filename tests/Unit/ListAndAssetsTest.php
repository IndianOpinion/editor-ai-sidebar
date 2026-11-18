<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Unit;

use IndianOpinion\EditorAiSidebar\Assets;
use IndianOpinion\EditorAiSidebar\Tests\Support\TestCase;

/**
 * Showing the sidebar and listing actions (PRD 4.2).
 */
final class ListAndAssetsTest extends TestCase
{
    private function enqueue(int $post, string $base = 'post'): void
    {
        \WpStub::$scripts = [];
        \WpStub::$inline = [];
        \WpStub::$screen = new \WP_Screen();
        \WpStub::$screen->base = $base;
        \WpStub::$currentPostId = $post;
        (new Assets($this->plugin))->enqueue();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $inline = \WpStub::$inline['editor-ai-sidebar'] ?? '';
        self::assertStringStartsWith('window.editorAiSidebarConfig = ', $inline);
        $config = json_decode(substr($inline, strlen('window.editorAiSidebarConfig = '), -1), true);
        self::assertIsArray($config);
        return $config;
    }

    public function testT11EnqueuedForPostsAndRestTypesOnly(): void
    {
        \WpStub::$postTypes['book'] = ['supports' => ['title', 'editor'], 'show_in_rest' => true];
        foreach (['post', 'book'] as $type) {
            $this->enqueue($this->post($type));
            self::assertTrue(\WpStub::$scripts['editor-ai-sidebar']['enqueued'] ?? false, $type);
        }
        foreach (['legacy', 'attachment'] as $type) {
            $this->enqueue($this->post($type));
            self::assertArrayNotHasKey('editor-ai-sidebar', \WpStub::$scripts, $type);
        }
        // Not in the site editor or the widget editor.
        foreach (['site-editor', 'widgets'] as $screen) {
            $this->enqueue($this->post(), $screen);
            self::assertArrayNotHasKey('editor-ai-sidebar', \WpStub::$scripts, $screen);
        }
        // Filterable.
        add_filter('editor_ai_sidebar_post_types', static fn (array $types): array => array_diff($types, ['book']));
        $this->enqueue($this->post('book'));
        self::assertArrayNotHasKey('editor-ai-sidebar', \WpStub::$scripts);
    }

    public function testScriptsHaveNoBuildStepAndUseGlobals(): void
    {
        $this->enqueue($this->post());
        $main = \WpStub::$scripts['editor-ai-sidebar'];
        self::assertStringEndsWith('/assets/js/sidebar.js', $main['src']);
        self::assertContains('wp-plugins', $main['deps']);
        self::assertContains('wp-api-fetch', $main['deps']);
        self::assertContains('editor-ai-sidebar-core', $main['deps']);
    }

    public function testT12UserWithoutEditPost(): void
    {
        $post = $this->post();
        \WpStub::$caps[self::EDITOR]['edit_post'] = false;
        $this->enqueue($post);
        self::assertArrayNotHasKey('editor-ai-sidebar', \WpStub::$scripts, 'sidebar not registered');
        $this->assertError($this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $post), 403, 'rest_forbidden');

        // Without the view capability every route is closed.
        \WpStub::$caps[self::EDITOR]['edit_post'] = true;
        add_filter('editor_ai_sidebar_view_capability', static fn (): string => 'use_ai_sidebar');
        $this->assertError($this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $post), 403, 'rest_forbidden');
        $this->enqueue($post);
        self::assertArrayNotHasKey('editor-ai-sidebar', \WpStub::$scripts);
    }

    public function testT13ConfigNeedsNoRequestAtLoad(): void
    {
        $this->provide($this->definition());
        $post = $this->post();
        $this->enqueue($post);
        $config = $this->config();
        self::assertSame($post, $config['postId']);
        self::assertTrue($config['hasActions']);
        self::assertSame('AI actions', $config['title']);
        self::assertSame('editor-ai-sidebar/v1', $config['namespace']);
        self::assertSame(524288, $config['contextLimit']);
        self::assertSame('en-GB', $config['language']);
        self::assertFalse($config['canManage']);
        self::assertArrayNotHasKey('actions', $config, 'the list is fetched on open, not printed at load');
    }

    public function testT16ZeroActions(): void
    {
        $post = $this->post();
        $this->enqueue($post);
        self::assertFalse($this->config()['hasActions']);

        wp_set_current_user(self::ADMIN);
        $this->enqueue($post);
        self::assertTrue($this->config()['canManage']);

        add_filter('editor_ai_sidebar_show_when_empty', '__return_false');
        $this->enqueue($post);
        self::assertArrayNotHasKey('editor-ai-sidebar', \WpStub::$scripts, 'button hidden when the filter says so');

        $this->provide($this->definition());
        $this->enqueue($post);
        self::assertTrue(\WpStub::$scripts['editor-ai-sidebar']['enqueued']);
    }

    public function testT19DisabledByConstantOrSetting(): void
    {
        $this->provide($this->definition());
        $post = $this->post();
        update_option('editor_ai_sidebar_settings', ['enabled' => false]);
        $this->enqueue($post);
        self::assertArrayNotHasKey('editor-ai-sidebar', \WpStub::$scripts);
        foreach (
            [
                ['GET', '/editor-ai-sidebar/v1/actions?post_id=' . $post],
                ['POST', '/editor-ai-sidebar/v1/actions/test/headline/run'],
                ['GET', '/editor-ai-sidebar/v1/jobs/' . str_repeat('a', 32)],
                ['DELETE', '/editor-ai-sidebar/v1/jobs/' . str_repeat('a', 32)],
                ['POST', '/editor-ai-sidebar/v1/results/' . str_repeat('a', 32) . '/apply'],
                ['POST', '/editor-ai-sidebar/v1/results/' . str_repeat('a', 32) . '/dismiss'],
            ] as [$method, $path]
        ) {
            $this->assertError($this->rest($method, $path), 503, 'sidebar_disabled');
        }
    }

    public function testT15ListIsOrderedByGroupThenOrderThenLabel(): void
    {
        $this->provide(
            $this->definition(['id' => 'p/other', 'label' => 'Other', 'group' => 'other']),
            $this->definition(['id' => 'p/meta', 'label' => 'Meta', 'group' => 'meta']),
            $this->definition(['id' => 'p/h-b', 'label' => 'B headline', 'group' => 'headlines', 'order' => 5]),
            $this->definition(['id' => 'p/h-a', 'label' => 'A headline', 'group' => 'headlines', 'order' => 5]),
            $this->definition(['id' => 'p/h-first', 'label' => 'Z headline', 'group' => 'headlines', 'order' => 1]),
            $this->definition(['id' => 'p/sum', 'label' => 'Summary', 'group' => 'summary'])
        );
        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $this->post())->get_data();
        self::assertSame(['p/sum', 'p/h-first', 'p/h-a', 'p/h-b', 'p/meta', 'p/other'], array_column($list, 'id'));
    }

    public function testDescriptorShape(): void
    {
        $this->provide($this->definition(['description' => 'One line.']));
        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $this->post())->get_data();
        $descriptor = $list[0];
        foreach (['id', 'label', 'description', 'group', 'order', 'icon', 'needs', 'selection', 'result', 'apply', 'editable', 'timeout', 'max_wait', 'available', 'reason', 'can_apply', 'pending_job'] as $key) {
            self::assertArrayHasKey($key, $descriptor);
        }
        self::assertArrayNotHasKey('run', $descriptor);
        self::assertStringNotContainsString('Closure', (string) json_encode($descriptor));
        self::assertTrue($descriptor['available']);
        self::assertTrue($descriptor['can_apply']);
    }

    public function testT17NoneForThisPostType(): void
    {
        $this->provide($this->definition(['post_types' => ['post']]));
        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $this->post('page'));
        self::assertSame(200, $list->get_status());
        self::assertSame([], $list->get_data());
    }

    public function testT18UnavailableActionsAreListedWithReason(): void
    {
        $this->provide(
            $this->definition(['id' => 'p/closed', 'available' => static fn (): string => 'Set an API key first.']),
            $this->definition(['id' => 'p/cap', 'run_capability' => 'publish_posts']),
            $this->definition(['id' => 'p/throws', 'available' => static function (): bool {
                throw new \LogicException('boom');
            }]),
            $this->definition(['id' => 'p/off'])
        );
        update_option('editor_ai_sidebar_settings', ['enabled' => true, 'disabled' => ['p/off']]);
        wp_set_current_user(self::AUTHOR);
        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $this->post())->get_data();
        $byId = array_column($list, null, 'id');
        self::assertArrayNotHasKey('p/off', $byId, 'switched-off actions are not listed');
        self::assertFalse($byId['p/closed']['available']);
        self::assertSame('Set an API key first.', $byId['p/closed']['reason']);
        self::assertFalse($byId['p/cap']['available']);
        self::assertSame('You do not have permission to run this action.', $byId['p/cap']['reason']);
        self::assertFalse($byId['p/throws']['available']);
        self::assertSame('This action is not available right now.', $byId['p/throws']['reason']);
    }

    public function testListWithZeroActionsAndMissingPost(): void
    {
        $list = $this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=' . $this->post());
        self::assertSame([], $list->get_data());
        $this->assertError($this->rest('GET', '/editor-ai-sidebar/v1/actions?post_id=0'), 404, 'post_missing');
        $this->assertError($this->rest('GET', '/editor-ai-sidebar/v1/actions'), 404, 'post_missing');
    }
}
