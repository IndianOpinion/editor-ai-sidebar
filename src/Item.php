<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * One proposed value. Immutable: the `with*` methods return a copy.
 *
 * Construction does not validate limits; the host validates every result
 * before it reaches a browser (PRD section 5.6).
 */
final class Item
{
    public const TEXT = 'text';
    public const LIST = 'list';
    public const FIELDS = 'fields';

    /**
     * @param string|array<int|string, mixed> $value
     * @param Note[] $notes
     */
    private function __construct(
        private readonly string $id,
        private readonly string $type,
        private readonly string|array $value,
        private readonly array $notes = [],
        private readonly ?float $score = null,
        private readonly ?string $scoreLabel = null,
        private readonly ?string $language = null,
        private readonly ?string $direction = null
    ) {
    }

    public static function text(string $id, string $value): self
    {
        return new self($id, self::TEXT, $value);
    }

    /**
     * @param array<int, string> $values
     */
    public static function list(string $id, array $values): self
    {
        return new self($id, self::LIST, $values);
    }

    /**
     * @param array<string, string> $values Field name to value.
     */
    public static function fields(string $id, array $values): self
    {
        return new self($id, self::FIELDS, $values);
    }

    /**
     * Rebuild an item from parts the host has already validated.
     *
     * @param string|array<int|string, mixed> $value
     * @param Note[] $notes
     */
    public static function make(
        string $id,
        string $type,
        string|array $value,
        array $notes = [],
        ?float $score = null,
        ?string $scoreLabel = null,
        ?string $language = null,
        ?string $direction = null
    ): self {
        return new self($id, $type, $value, $notes, $score, $scoreLabel, $language, $direction);
    }

    public function withNote(Note $note): self
    {
        $notes = $this->notes;
        $notes[] = $note;
        return new self(
            $this->id,
            $this->type,
            $this->value,
            $notes,
            $this->score,
            $this->scoreLabel,
            $this->language,
            $this->direction
        );
    }

    public function withScore(float $score, string $label): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->value,
            $this->notes,
            $score,
            $label,
            $this->language,
            $this->direction
        );
    }

    public function withLanguage(string $tag, string $dir = 'ltr'): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->value,
            $this->notes,
            $this->score,
            $this->scoreLabel,
            $tag,
            $dir
        );
    }

    /**
     * @param string|array<int|string, mixed> $value
     */
    public function withValue(string|array $value): self
    {
        return new self(
            $this->id,
            $this->type,
            $value,
            $this->notes,
            $this->score,
            $this->scoreLabel,
            $this->language,
            $this->direction
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return string|array<int|string, mixed>
     */
    public function value(): string|array
    {
        return $this->value;
    }

    /**
     * @return Note[]
     */
    public function notes(): array
    {
        return $this->notes;
    }

    public function score(): ?float
    {
        return $this->score;
    }

    public function scoreLabel(): ?string
    {
        return $this->scoreLabel;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    public function direction(): ?string
    {
        return $this->direction;
    }

    /**
     * The array form of section 5.6.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'value' => $this->value,
            'notes' => array_map(static fn (Note $note): array => $note->toArray(), $this->notes),
            'score' => $this->score,
            'score_label' => $this->scoreLabel,
            'lang' => $this->language,
            'dir' => $this->direction,
        ];
    }
}
