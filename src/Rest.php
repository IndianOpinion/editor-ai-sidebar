<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * REST routes under `editor-ai-sidebar/v1` (PRD section 5.4).
 */
final class Rest
{
    public const NAMESPACE = 'editor-ai-sidebar/v1';

    public function __construct(
        private readonly Service $service,
        private readonly Settings $settings
    ) {
    }

    public function register(): void
    {
        $permission = [$this, 'permission'];
        register_rest_route(self::NAMESPACE, '/actions', [
            'methods' => 'GET',
            'callback' => [$this, 'listActions'],
            'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/actions/(?P<id>[a-z0-9-]+/[a-z0-9-]+)/run', [
            'methods' => 'POST',
            'callback' => [$this, 'run'],
            'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/jobs/(?P<job_id>[A-Za-z0-9_-]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'poll'],
                'permission_callback' => $permission,
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'cancel'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/results/(?P<result_id>[A-Za-z0-9_-]+)/apply', [
            'methods' => 'POST',
            'callback' => [$this, 'apply'],
            'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/results/(?P<result_id>[A-Za-z0-9_-]+)/dismiss', [
            'methods' => 'POST',
            'callback' => [$this, 'dismiss'],
            'permission_callback' => $permission,
        ]);
    }

    /**
     * Shared gate: sidebar on, user logged in, view capability held.
     * Core has already checked the `wp_rest` nonce under cookie auth.
     */
    public function permission(\WP_REST_Request $request): bool|\WP_Error
    {
        unset($request);
        if (!$this->settings->sidebarEnabled()) {
            return new \WP_Error('sidebar_disabled', Messages::forCode('sidebar_disabled'), ['status' => 503]);
        }
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_forbidden', Messages::forCode('rest_forbidden'), ['status' => 401]);
        }
        if (!current_user_can(Plugin::viewCapability())) {
            return new \WP_Error('rest_forbidden', Messages::forCode('rest_forbidden'), ['status' => 403]);
        }
        return true;
    }

    public function listActions(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->guard(fn (): \WP_REST_Response => new \WP_REST_Response(
            $this->service->listActions($request->get_param('post_id'), get_current_user_id()),
            200
        ));
    }

    public function run(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->guard(function () use ($request): \WP_REST_Response {
            $answer = $this->service->run(
                (string) $request->get_param('id'),
                $request->get_param('post_id'),
                $request->get_param('context'),
                $request->get_param('run_token'),
                get_current_user_id()
            );
            return new \WP_REST_Response($answer['body'], $answer['status']);
        });
    }

    public function poll(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->guard(function () use ($request): \WP_REST_Response {
            $answer = $this->service->poll((string) $request->get_param('job_id'), get_current_user_id());
            return new \WP_REST_Response($answer['body'], $answer['status']);
        });
    }

    public function cancel(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->guard(function () use ($request): \WP_REST_Response {
            $this->service->cancelJob((string) $request->get_param('job_id'), get_current_user_id());
            return new \WP_REST_Response(null, 204);
        });
    }

    public function apply(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->guard(fn (): \WP_REST_Response => new \WP_REST_Response(
            $this->service->apply(
                (string) $request->get_param('result_id'),
                self::params($request),
                get_current_user_id()
            ),
            200
        ));
    }

    public function dismiss(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->guard(function () use ($request): \WP_REST_Response {
            $this->service->dismiss(
                (string) $request->get_param('result_id'),
                self::params($request),
                get_current_user_id()
            );
            return new \WP_REST_Response(null, 204);
        });
    }

    /**
     * Adds `Cache-Control: no-store`, the contract header and `Retry-After`
     * to every response from this namespace, errors included (case 79).
     *
     * @param mixed $response
     * @return mixed
     */
    public static function headers($response, mixed $server, mixed $request)
    {
        unset($server);
        if (!$response instanceof \WP_REST_Response || !$request instanceof \WP_REST_Request) {
            return $response;
        }
        if (!str_starts_with(ltrim($request->get_route(), '/'), self::NAMESPACE)) {
            return $response;
        }
        $response->header('Cache-Control', 'no-store');
        $response->header('X-Editor-AI-Sidebar-Contract', implode(',', Plugin::CONTRACT_VERSIONS));
        $data = $response->get_data();
        if (is_array($data) && isset($data['data']['retry_after']) && is_int($data['data']['retry_after'])) {
            $response->header('Retry-After', (string) $data['data']['retry_after']);
        }
        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function params(\WP_REST_Request $request): array
    {
        $params = [];
        foreach (['post_id', 'action_id', 'item_id', 'value', 'approved', 'context_hash', 'confirm_stale'] as $key) {
            $params[$key] = $request->get_param($key);
        }
        return $params;
    }

    /**
     * @param \Closure(): \WP_REST_Response $call
     */
    private function guard(\Closure $call): \WP_REST_Response|\WP_Error
    {
        try {
            return $call();
        } catch (ApiError $e) {
            return $e->toWpError();
        }
    }
}
