<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * A short note shown with a result or an item.
 */
final class Note
{
    public const INFO = 'info';
    public const WARNING = 'warning';

    private function __construct(
        private readonly string $level,
        private readonly string $text
    ) {
    }

    public static function info(string $text): self
    {
        return new self(self::INFO, $text);
    }

    public static function warning(string $text): self
    {
        return new self(self::WARNING, $text);
    }

    public function level(): string
    {
        return $this->level;
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * @return array{level: string, text: string}
     */
    public function toArray(): array
    {
        return ['level' => $this->level, 'text' => $this->text];
    }
}
