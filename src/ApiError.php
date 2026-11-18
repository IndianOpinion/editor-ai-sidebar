<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * A refusal with a contract error code and an HTTP status.
 *
 * The service layer throws it; the REST layer turns it into a `WP_Error`.
 */
final class ApiError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $data Extra response data (for example `retry_after`).
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status,
        private readonly array $data = []
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function toWpError(): \WP_Error
    {
        return new \WP_Error($this->errorCode, $this->getMessage(), ['status' => $this->status] + $this->data);
    }
}
