<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * What a run returns: one item, two to 20 choices, or nothing.
 */
final class Result
{
    public const SINGLE = 'single';
    public const CHOICES = 'choices';

    /**
     * @param Item[] $items
     * @param Note[] $notes
     */
    private function __construct(
        private readonly string $layout,
        private readonly array $items,
        private readonly array $notes
    ) {
    }

    /**
     * @param Note[] $notes
     */
    public static function single(Item $item, array $notes = []): self
    {
        return new self(self::SINGLE, [$item], $notes);
    }

    /**
     * @param Item[] $items
     * @param Note[] $notes
     */
    public static function choices(array $items, array $notes = []): self
    {
        return new self(self::CHOICES, array_values($items), $notes);
    }

    /**
     * A result with no items: shown as "No suggestions this time." (case 75).
     *
     * @param Note[] $notes
     */
    public static function none(array $notes = []): self
    {
        return new self(self::SINGLE, [], $notes);
    }

    public function layout(): string
    {
        return $this->layout;
    }

    /**
     * @return Item[]
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return Note[]
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'layout' => $this->layout,
            'items' => array_map(static fn (Item $item): array => $item->toArray(), $this->items),
            'notes' => array_map(static fn (Note $note): array => $note->toArray(), $this->notes),
        ];
    }
}
