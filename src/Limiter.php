<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * The host rate limit per user (case 28) and the daily run cap per action
 * (ruling 4). Both are safety nets kept in transients; neither is a spend
 * cap, and neither is exact under heavy concurrency.
 */
final class Limiter
{
    /**
     * Count a run against the user's window.
     *
     * @return int 0 when allowed, else seconds until the window resets.
     */
    public function hitRateLimit(int $userId): int
    {
        $limit = apply_filters('editor_ai_sidebar_rate_limit', ['runs' => 30, 'window' => 600]);
        $runs = is_array($limit) && isset($limit['runs']) ? (int) $limit['runs'] : 30;
        $window = is_array($limit) && isset($limit['window']) ? (int) $limit['window'] : 600;
        if ($runs < 1 || $window < 1) {
            return 0;
        }
        $key = 'editor_ai_sidebar_rl_' . $userId;
        $now = Clock::now();
        $state = get_transient($key);
        if (!is_array($state) || !isset($state['start'], $state['count']) || $now - (int) $state['start'] >= $window) {
            $state = ['start' => $now, 'count' => 0];
        }
        if ((int) $state['count'] >= $runs) {
            return max(1, (int) $state['start'] + $window - $now);
        }
        $state['count'] = (int) $state['count'] + 1;
        set_transient($key, $state, max(1, (int) $state['start'] + $window - $now));
        return 0;
    }

    /**
     * The cap for an action today; 0 means the site switched the host cap off.
     */
    public function dailyCap(ActionDefinition $definition): int
    {
        return max(0, (int) apply_filters('editor_ai_sidebar_daily_cap', $definition->dailyCap(), $definition->id()));
    }

    public function dailyCapReached(ActionDefinition $definition): bool
    {
        $cap = $this->dailyCap($definition);
        return $cap > 0 && $this->dailyCount($definition->id()) >= $cap;
    }

    public function countDaily(string $actionId): void
    {
        $key = self::dailyKey($actionId);
        set_transient($key, $this->dailyCount($actionId) + 1, 2 * DAY_IN_SECONDS);
    }

    public function dailyCount(string $actionId): int
    {
        $count = get_transient(self::dailyKey($actionId));
        return is_numeric($count) ? (int) $count : 0;
    }

    private static function dailyKey(string $actionId): string
    {
        return 'editor_ai_sidebar_dc_' . md5($actionId) . '_' . gmdate('Ymd', Clock::now());
    }
}
