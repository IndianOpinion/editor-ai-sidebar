<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Read-only view of an approved apply, handed to `apply_callback`.
 */
final class ApplyRequest
{
    public function __construct(
        private readonly string $actionId,
        private readonly int $postId,
        private readonly int $userId,
        private readonly string $resultId,
        private readonly Item $item,
        private readonly bool $edited,
        private readonly bool $confirmStale
    ) {
    }

    public function actionId(): string
    {
        return $this->actionId;
    }

    public function postId(): int
    {
        return $this->postId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function resultId(): string
    {
        return $this->resultId;
    }

    /**
     * The final item, with the edited value when the user edited it.
     */
    public function item(): Item
    {
        return $this->item;
    }

    public function edited(): bool
    {
        return $this->edited;
    }

    public function confirmStale(): bool
    {
        return $this->confirmStale;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'post_id' => $this->postId,
            'user_id' => $this->userId,
            'result_id' => $this->resultId,
            'item' => $this->item->toArray(),
            'edited' => $this->edited,
            'confirm_stale' => $this->confirmStale,
        ];
    }
}
