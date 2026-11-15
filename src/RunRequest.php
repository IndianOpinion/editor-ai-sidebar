<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Read-only view of a run, handed to a provider's `run` and `poll`.
 */
final class RunRequest
{
    /**
     * @param array<string, mixed> $context Only the declared needs.
     * @param array{name: string, clientId: string, attributes: array<string, mixed>}|null $selection
     */
    public function __construct(
        private readonly string $actionId,
        private readonly int $postId,
        private readonly int $userId,
        private readonly array $context,
        private readonly ?array $selection,
        private readonly string $language,
        private readonly string $contextHash
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

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{name: string, clientId: string, attributes: array<string, mixed>}|null
     */
    public function selection(): ?array
    {
        return $this->selection;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function contextHash(): string
    {
        return $this->contextHash;
    }

    /**
     * The plain-array form for providers that do not use the classes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'post_id' => $this->postId,
            'user_id' => $this->userId,
            'context' => $this->context,
            'selection' => $this->selection,
            'language' => $this->language,
            'context_hash' => $this->contextHash,
        ];
    }
}
