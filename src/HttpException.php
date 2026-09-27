<?php

declare(strict_types=1);

namespace PdfApi;

final class HttpException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{error: string, message: string, details: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'error' => $this->error,
            'message' => $this->getMessage(),
            'details' => $this->details,
        ];
    }
}
