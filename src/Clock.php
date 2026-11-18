<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Wall clock in whole seconds. Tests may freeze it.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    /**
     * Freeze the clock at a time, or pass null to follow the real clock.
     */
    public static function set(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }

    public static function advance(int $seconds): void
    {
        self::$frozen = self::now() + $seconds;
    }
}
