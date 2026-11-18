<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

use IndianOpinion\EditorAiSidebar\Store\RecordStore;

/**
 * The host rate limit per user (case 28) and the daily run cap per action
 * (ruling 4). Neither is a spend cap. The rate limit is a click guard kept
 * in a transient and is not exact under heavy concurrency. The daily cap
 * is a counter row in the record table, taken with one conditional UPDATE,
 * so concurrent runs cannot push it past the cap.
 */
final class Limiter
{
    public function __construct(private readonly RecordStore $store)
    {
    }

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

    /**
     * Count one run against the action's cap for today, atomically.
     * False when the cap was already reached; nothing is counted then.
     */
    public function takeDaily(ActionDefinition $definition): bool
    {
        $cap = $this->dailyCap($definition);
        if ($cap === 0) {
            return true;
        }
        $now = Clock::now();
        return $this->store->incrementCounter(
            self::dailyKey($definition->id()),
            $definition->id(),
            $cap,
            $now,
            $now + 2 * DAY_IN_SECONDS
        );
    }

    public function dailyCount(string $actionId): int
    {
        return $this->store->counter(self::dailyKey($actionId));
    }

    private static function dailyKey(string $actionId): string
    {
        return 'cap-' . md5($actionId) . '-' . gmdate('Ymd', Clock::now());
    }
}
