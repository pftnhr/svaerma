<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200, bool $pretty = true): self
    {
        // Invalid UTF-8 in stored data must not turn every JSON response into a 500.
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0);
        $body = json_encode($data, $flags);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function send(): void
    {
        header_remove('X-Powered-By');
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
