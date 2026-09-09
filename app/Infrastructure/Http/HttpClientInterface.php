<?php

namespace App\Infrastructure\Http;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string|int|float> $query
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $query = [],
        int $timeout = 20,
    ): HttpResponse;
}
