<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\Osm\HttpClient;

/** Records requests and returns canned responses. */
final class FakeHttpClient extends HttpClient
{
    /** @var list<array{method: string, url: string, data: array<string, mixed>}> */
    public array $requests = [];

    /** @var \Closure(string): string */
    private \Closure $responder;

    /**
     * @param string|callable(string): string $response fixed body, or a function of the URL (may throw)
     */
    public function __construct(string|callable $response)
    {
        parent::__construct('test');
        $this->responder = is_string($response)
            ? static fn (string $url): string => $response
            : \Closure::fromCallable($response);
    }

    /** @var list<array<string, string>> */
    public array $headers = [];

    public function get(string $url, array $query = [], array $headers = []): string
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'data' => $query];
        $this->headers[] = $headers;
        return ($this->responder)($url);
    }

    public function fetch(string $url): array
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'data' => []];
        try {
            return ['status' => 200, 'body' => ($this->responder)($url), 'ip' => '', 'location' => null];
        } catch (\App\Service\Osm\OsmException $e) {
            if (preg_match('/HTTP (\d{3})/', $e->getMessage(), $m)) {
                return ['status' => (int) $m[1], 'body' => '', 'ip' => '', 'location' => null];
            }
            throw $e;
        }
    }

    public function post(string $url, array $form): string
    {
        $this->requests[] = ['method' => 'POST', 'url' => $url, 'data' => $form];
        return ($this->responder)($url);
    }
}
