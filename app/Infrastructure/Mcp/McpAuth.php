<?php

namespace App\Infrastructure\Mcp;

final class McpAuth
{
    public function extractBearer(?string $authorizationHeader, ?string $queryToken = null): string
    {
        $header = trim((string)$authorizationHeader);
        if ($header !== '' && preg_match('/^Bearer\s+(\S+)/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return trim((string)$queryToken);
    }

    public function verify(string $provided, string $expected): bool
    {
        if ($expected === '' || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
