<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Support;

use IndianOpinion\EditorAiSidebar\Clock;
use IndianOpinion\EditorAiSidebar\Plugin;
use IndianOpinion\EditorAiSidebar\Rest;

/**
 * Base for the PHP tests: a fresh stub WordPress, a frozen clock, the
 * plugin wired to an in-memory store, and a small REST dispatcher that
 * follows core's order (route, permission callback, callback, then
 * `rest_post_dispatch`).
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const EDITOR = 2;
    public const AUTHOR = 3;
    public const ADMIN = 1;
    public const OTHER_EDITOR = 4;

    protected MemoryRecordStore $store;
    protected Plugin $plugin;

    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    protected array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::reset();
        Clock::set(1_800_000_000);
        $this->store = new MemoryRecordStore();
        $this->plugin = Plugin::reset($this->store);
        $this->plugin->service()->setClientGoneCheck(static fn (): bool => false);
        $this->plugin->rest()->register();
        add_filter('rest_post_dispatch', [Rest::class, 'headers'], 10, 3);
        $this->fired = [];
        foreach (
            [
                'editor_ai_sidebar_run_started' => 3,
                'editor_ai_sidebar_result_issued' => 5,
                'editor_ai_sidebar_run_failed' => 4,
                'editor_ai_sidebar_applied' => 7,
                'editor_ai_sidebar_dismissed' => 4,
                'editor_ai_sidebar_contract_violation' => 3,
            ] as $hook => $args
        ) {
            add_action($hook, function (mixed ...$params) use ($hook): void {
                $this->fired[] = [$hook, $params];
            }, 10, $args);
        }
        $this->grantAll(self::ADMIN, true);
        $this->grantAll(self::EDITOR, false);
        $this->grantAll(self::OTHER_EDITOR, false);
        \WpStub::$caps[self::AUTHOR] = [
            'edit_posts' => true,
            'edit_post' => true,
            'publish_posts' => false,
        ];
        wp_set_current_user(self::EDITOR);
    }

    protected function tearDown(): void
    {
        Clock::set(null);
        parent::tearDown();
    }

    private function grantAll(int $user, bool $admin): void
    {
        \WpStub::$caps[$user] = [
            'edit_posts' => true,
            'edit_post' => true,
            'edit_others_posts' => true,
            'publish_posts' => true,
            'manage_options' => $admin,
        ];
    }

    protected function post(string $type = 'post', string $status = 'draft', array $fields = []): int
    {
        $id = \WpStub::$nextPostId++;
        \WpStub::$posts[$id] = new \WP_Post(array_merge([
            'ID' => $id,
            'post_type' => $type,
            'post_status' => $status,
            'post_title' => 'A title',
        ], $fields));
        return $id;
    }

    /**
     * Register definitions through the filter, as a provider would.
     *
     * @param mixed ...$definitions
     */
    protected function provide(mixed ...$definitions): void
    {
        add_filter('editor_ai_sidebar_actions', static function (array $actions) use ($definitions): array {
            foreach ($definitions as $index => $definition) {
                $key = is_array($definition) && is_string($definition['id'] ?? null) ? $definition['id'] : 'k' . $index;
                if (isset($actions[$key])) {
                    $key .= '#' . $index;
                }
                $actions[$key] = $definition;
            }
            return $actions;
        });
        $this->plugin->registry()->reset();
    }

    /**
     * A valid editor-mode definition with overrides.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function definition(array $overrides = []): array
    {
        return array_replace([
            'id' => 'test/headline',
            'label' => 'Suggest a headline',
            'group' => 'headlines',
            'needs' => ['title', 'text'],
            'result' => ['layout' => 'single', 'item_type' => 'text'],
            'apply' => ['mode' => 'editor', 'operation' => 'set_title'],
            'run' => static fn (\IndianOpinion\EditorAiSidebar\RunRequest $r): \IndianOpinion\EditorAiSidebar\Result =>
                \IndianOpinion\EditorAiSidebar\Result::single(\IndianOpinion\EditorAiSidebar\Item::text('a', 'New headline')),
        ], $overrides);
    }

    /**
     * Dispatch a REST request through the registered routes.
     *
     * @param array<string, mixed> $params
     */
    protected function rest(string $method, string $path, array $params = []): \WP_REST_Response
    {
        $route = (string) strtok($path, '?');
        $query = (string) parse_url($path, PHP_URL_QUERY);
        parse_str($query, $queryParams);
        $request = new \WP_REST_Request($method, $route);
        foreach (array_merge($queryParams, $params) as $key => $value) {
            $request->set_param((string) $key, $value);
        }
        $response = null;
        foreach (\WpStub::$routes as $pattern => $handlers) {
            if (preg_match('@^' . $pattern . '$@', $route, $matches) !== 1) {
                continue;
            }
            foreach ($handlers as $handler) {
                if (strtoupper((string) $handler['methods']) !== $method) {
                    continue;
                }
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $request->set_param($key, $value);
                    }
                }
                $allowed = ($handler['permission_callback'])($request);
                if ($allowed instanceof \WP_Error) {
                    $result = $allowed;
                } elseif ($allowed !== true) {
                    $result = new \WP_Error('rest_forbidden', 'Sorry.', ['status' => 403]);
                } else {
                    $result = ($handler['callback'])($request);
                }
                $response = $result instanceof \WP_Error ? self::errorResponse($result) : $result;
                break 2;
            }
        }
        if ($response === null) {
            $response = self::errorResponse(new \WP_Error('rest_no_route', 'No route.', ['status' => 404]));
        }
        $filtered = apply_filters('rest_post_dispatch', $response, null, $request);
        self::assertInstanceOf(\WP_REST_Response::class, $filtered);
        return $filtered;
    }

    private static function errorResponse(\WP_Error $error): \WP_REST_Response
    {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
        return new \WP_REST_Response([
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data' => is_array($data) ? $data : ['status' => $status],
        ], $status);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function runAction(string $actionId, int $postId, array $context, array $extra = []): \WP_REST_Response
    {
        return $this->rest('POST', '/editor-ai-sidebar/v1/actions/' . $actionId . '/run', array_merge([
            'post_id' => $postId,
            'context' => $context,
        ], $extra));
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $overrides
     */
    protected function applyItem(array $envelope, string $itemId, array $overrides = []): \WP_REST_Response
    {
        $value = null;
        foreach ($envelope['items'] as $item) {
            if ($item['id'] === $itemId) {
                $value = $item['value'];
            }
        }
        return $this->rest('POST', '/editor-ai-sidebar/v1/results/' . $envelope['result_id'] . '/apply', array_merge([
            'post_id' => $envelope['post_id'],
            'action_id' => $envelope['action_id'],
            'item_id' => $itemId,
            'value' => $value,
            'approved' => true,
            'context_hash' => $envelope['context_hash'],
        ], $overrides));
    }

    protected function assertError(\WP_REST_Response $response, int $status, string $code): void
    {
        $data = $response->get_data();
        self::assertSame($status, $response->get_status(), 'status; body: ' . json_encode($data));
        self::assertIsArray($data);
        self::assertSame($code, $data['code']);
    }

    /**
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
    protected function firedOf(string $hook): array
    {
        return array_values(array_filter($this->fired, static fn (array $f): bool => $f[0] === $hook));
    }

    /**
     * @return string[]
     */
    protected function loggedEvents(): array
    {
        return array_map(static fn (array $e): string => $e['event'] . ':' . $e['code'], $this->plugin->log()->all());
    }
}
