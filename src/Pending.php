<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * A queued run. The provider reference never leaves the server.
 */
final class Pending
{
    private function __construct(
        private readonly string $providerRef,
        private readonly int $pollAfter
    ) {
    }

    public static function job(string $provider_ref, int $poll_after_seconds = 3): self
    {
        return new self($provider_ref, $poll_after_seconds);
    }

    public function providerRef(): string
    {
        return $this->providerRef;
    }

    public function pollAfter(): int
    {
        return $this->pollAfter;
    }
}
