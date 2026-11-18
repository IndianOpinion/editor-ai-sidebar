<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * A ring of the latest events in one option that is not autoloaded.
 *
 * Holds ids and codes only: never item text, never context (lesson 10).
 */
final class EventLog
{
    public const OPTION = 'editor_ai_sidebar_log';
    public const SIZE = 500;

    /**
     * @param array{action_id?: string, post_id?: int, user_id?: int, result_id?: string, code?: string, detail?: string} $fields
     */
    public function add(string $event, array $fields = []): void
    {
        $entries = $this->all();
        $entries[] = [
            'time' => Clock::now(),
            'event' => $event,
            'action_id' => (string) ($fields['action_id'] ?? ''),
            'post_id' => (int) ($fields['post_id'] ?? 0),
            'user_id' => (int) ($fields['user_id'] ?? 0),
            'result_id' => (string) ($fields['result_id'] ?? ''),
            'code' => (string) ($fields['code'] ?? ''),
            'detail' => self::clip((string) ($fields['detail'] ?? '')),
        ];
        if (count($entries) > self::SIZE) {
            $entries = array_slice($entries, -self::SIZE);
        }
        update_option(self::OPTION, $entries, false);
    }

    /**
     * Oldest first.
     *
     * @return array<int, array{time: int, event: string, action_id: string, post_id: int, user_id: int, result_id: string, code: string, detail: string}>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $entry) {
            if (is_array($entry) && isset($entry['event'], $entry['time'])) {
                $out[] = [
                    'time' => (int) $entry['time'],
                    'event' => (string) $entry['event'],
                    'action_id' => (string) ($entry['action_id'] ?? ''),
                    'post_id' => (int) ($entry['post_id'] ?? 0),
                    'user_id' => (int) ($entry['user_id'] ?? 0),
                    'result_id' => (string) ($entry['result_id'] ?? ''),
                    'code' => (string) ($entry['code'] ?? ''),
                    'detail' => (string) ($entry['detail'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Newest first, at most $limit entries.
     *
     * @return array<int, array{time: int, event: string, action_id: string, post_id: int, user_id: int, result_id: string, code: string, detail: string}>
     */
    public function latest(int $limit): array
    {
        return array_slice(array_reverse($this->all()), 0, max(0, $limit));
    }

    /**
     * Applies and failures per action over the log window.
     *
     * @return array<string, array{applied: int, failed: int}>
     */
    public function countsByAction(): array
    {
        $counts = [];
        foreach ($this->all() as $entry) {
            if ($entry['action_id'] === '' || !in_array($entry['event'], ['applied', 'run_failed'], true)) {
                continue;
            }
            $counts[$entry['action_id']] ??= ['applied' => 0, 'failed' => 0];
            if ($entry['event'] === 'applied') {
                $counts[$entry['action_id']]['applied']++;
            } else {
                $counts[$entry['action_id']]['failed']++;
            }
        }
        ksort($counts);
        return $counts;
    }

    private static function clip(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = self::redact($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, 300) : substr($text, 0, 300);
    }

    /**
     * Provider exception messages can quote a request URL or header. Drop
     * query strings, URL credentials and anything that looks like a key,
     * token, secret or password before it reaches the shared log.
     */
    private static function redact(string $text): string
    {
        $rules = [
            '#(https?://)[^/\s:@]+:[^/\s@]+@#i' => '$1[redacted]@',
            '#(https?://[^\s?]+)\?\S*#i' => '$1?[redacted]',
            '/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 [redacted]',
            '/\b([A-Za-z_-]*(?:key|token|secret|password|passwd|authorization))(["\']?\s*[:=]\s*["\']?)[^\s"\',;&]+/i'
                => '$1$2[redacted]',
            '/\b(sk|pk|rk)-[A-Za-z0-9_-]{16,}/' => '[redacted]',
        ];
        return (string) preg_replace(array_keys($rules), array_values($rules), $text);
    }
}
