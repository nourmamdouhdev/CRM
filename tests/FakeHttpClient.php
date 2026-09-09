<?php

namespace Tests;

use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Http\HttpResponse;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method:string,url:string,headers:array,body:?string,query:array}> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    private array $queue = [];

    public function queue(int $status, string $body, array $headers = []): void
    {
        $this->queue[] = new HttpResponse($status, $body, $headers);
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $query = [],
        int $timeout = 20,
    ): HttpResponse {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'query');
        if ($this->queue === []) {
            return new HttpResponse(200, '{"ok":true}');
        }

        return array_shift($this->queue);
    }
}
