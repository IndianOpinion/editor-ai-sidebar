<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Turns a PHP fatal inside a provider call into `provider_error` (case 37).
 *
 * WordPress's own fatal handler runs first at shutdown and keeps recovery
 * mode working; while a provider call is armed this class rewrites its
 * error code and message and logs the event. If that handler is switched
 * off, the shutdown fallback here answers instead.
 */
final class FatalGuard
{
    private ?string $actionId = null;
    private int $postId = 0;
    private int $userId = 0;
    private bool $handled = false;
    private bool $registered = false;

    public function __construct(private readonly EventLog $log)
    {
    }

    public function arm(string $actionId, int $postId, int $userId): void
    {
        $this->actionId = $actionId;
        $this->postId = $postId;
        $this->userId = $userId;
        $this->handled = false;
        add_filter('wp_php_error_args', [$this, 'filterArgs'], 10, 2);
        add_filter('wp_php_error_message', [$this, 'filterMessage'], 10, 2);
        if (!$this->registered) {
            $this->registered = true;
            register_shutdown_function([$this, 'onShutdown']);
        }
    }

    public function disarm(): void
    {
        $this->actionId = null;
        remove_filter('wp_php_error_args', [$this, 'filterArgs'], 10);
        remove_filter('wp_php_error_message', [$this, 'filterMessage'], 10);
    }

    public function armed(): bool
    {
        return $this->actionId !== null;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $error
     * @return array<string, mixed>
     */
    public function filterArgs(array $args, array $error): array
    {
        if (!$this->armed()) {
            return $args;
        }
        $this->record($error);
        $args['response'] = 500;
        $args['code'] = 'provider_error';
        return $args;
    }

    /**
     * @param array<string, mixed> $error
     */
    public function filterMessage(string $message, array $error): string
    {
        if (!$this->armed()) {
            return $message;
        }
        $this->record($error);
        return Messages::providerError();
    }

    public function onShutdown(): void
    {
        if (!$this->armed()) {
            return;
        }
        $error = error_get_last();
        if (!is_array($error) || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return;
        }
        $this->record($error);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo wp_json_encode([
                'code' => 'provider_error',
                'message' => Messages::providerError(),
                'data' => ['status' => 500],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $error
     */
    private function record(array $error): void
    {
        if ($this->handled || $this->actionId === null) {
            return;
        }
        $this->handled = true;
        $detail = 'PHP fatal: ' . (string) ($error['message'] ?? '');
        $this->log->add('run_failed', [
            'action_id' => $this->actionId,
            'post_id' => $this->postId,
            'user_id' => $this->userId,
            'code' => 'provider_error',
            'detail' => $detail,
        ]);
        do_action('editor_ai_sidebar_contract_violation', $this->actionId, 'provider_error', ['reason' => 'fatal']);
        do_action('editor_ai_sidebar_run_failed', $this->actionId, $this->postId, $this->userId, 'provider_error');
    }
}
