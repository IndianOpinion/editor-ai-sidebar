<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * What a server-mode apply reports back.
 */
final class ApplyOutcome
{
    /**
     * @param string[] $targets
     * @param array<int, array{label: string, url: string}> $links
     */
    private function __construct(
        private readonly string $message,
        private readonly array $targets,
        private readonly array $links
    ) {
    }

    /**
     * @param string[] $targets Target strings of section 5.5 that changed.
     * @param array<int, array{label: string, url: string}> $links Admin URLs on this site.
     */
    public static function done(string $message, array $targets, array $links = []): self
    {
        return new self($message, array_values($targets), array_values($links));
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return string[]
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * @return array<int, array{label: string, url: string}>
     */
    public function links(): array
    {
        return $this->links;
    }
}
