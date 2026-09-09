<?php

namespace App\Infrastructure\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
