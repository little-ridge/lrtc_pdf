<?php

declare(strict_types=1);

namespace PdfApi;

final class ApiResponse
{
    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $json = null,
        public readonly ?string $pdf = null,
        public readonly array $headers = [],
    ) {
    }
}
