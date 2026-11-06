<?php

declare(strict_types=1);

namespace App\Service\Osm;

use App\Http\IpRange;

/**
 * Minimal HTTP client for the OSM APIs. Uses cURL when available, otherwise PHP streams.
 * Not final so tests can replace the transport.
 *
 * Redirects are followed by hand: HTTPS only, at most MAX_REDIRECTS, and extra headers
 * (API keys) are dropped as soon as a redirect leaves the original host.
 */
class HttpClient
{
    private const MAX_REDIRECTS = 3;

    /**
     * @param float|null $deadline unix time (microtime) after which no request may run;
     *                             shortens timeouts so a chain of fallbacks has a total budget
     */
    public function __construct(
        private string $userAgent,
        private int $timeout = 15,
        private ?float $deadline = null,
    ) {
    }

    /**
     * @param array<string, string|int|float> $query
     * @param array<string, string> $headers extra request headers, e.g. an API key
     */
    public function get(string $url, array $query = [], array $headers = []): string
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return self::checked($this->send('GET', $url, null, $headers));
    }

    /** @param array<string, string> $form */
    public function post(string $url, array $form): string
    {
        return self::checked($this->send('POST', $url, http_build_query($form)));
    }

    /**
     * Plain GET without status handling, e.g. for self-checks.
     *
     * @return array{status: int, body: string, ip: string, location: ?string}
     * @throws OsmException only when no connection could be made
     */
    public function fetch(string $url): array
    {
        return $this->send('GET', $url, null, []);
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{status: int, body: string, ip: string, location: ?string}
     */
    private function send(string $method, string $url, ?string $body = null, array $extraHeaders = []): array
    {
        $originalHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        for ($hop = 0; ; $hop++) {
            $response = $this->request($method, $url, $body, $extraHeaders);
            if ($response['location'] === null || $hop >= self::MAX_REDIRECTS) {
                return $response;
            }
            $next = self::resolve($url, $response['location']);
            $host = strtolower((string) parse_url($next, PHP_URL_HOST));
            if (!str_starts_with(strtolower($next), 'https://') || $host === '') {
                throw new OsmException('Weiterleitung auf eine unsichere Adresse wurde nicht verfolgt.');
            }
            if ($host !== $originalHost) {
                if (!IpRange::hostIsPublic($host)) {
                    throw new OsmException('Weiterleitung auf eine interne Adresse wurde nicht verfolgt.');
                }
                // Secrets were meant for the original host only.
                $extraHeaders = [];
            }
            // Like browsers: 301/302/303 continue as GET without body.
            if (in_array($response['status'], [301, 302, 303], true)) {
                $method = 'GET';
                $body = null;
            }
            $url = $next;
        }
    }

    /**
     * One request, no redirects.
     *
     * @param array<string, string> $extraHeaders
     * @return array{status: int, body: string, ip: string, location: ?string}
     */
    private function request(string $method, string $url, ?string $body, array $extraHeaders): array
    {
        $timeout = $this->timeout;
        if ($this->deadline !== null) {
            $left = (int) floor($this->deadline - microtime(true));
            if ($left < 1) {
                throw new OsmException('Zeitbudget für diese Anfrage aufgebraucht.');
            }
            $timeout = min($timeout, $left);
        }

        // No "Expect: 100-continue" – some outbound proxies stall on it.
        $headers = ['Accept: application/json', 'Expect:'];
        foreach ($extraHeaders as $name => $value) {
            $headers[] = "$name: $value";
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => '',
            ]);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
            }
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error = curl_error($ch);
            if (!is_string($response)) {
                throw new OsmException('Verbindung fehlgeschlagen: ' . $error . ($ip !== '' ? " [IP $ip]" : ''));
            }
            $location = is_string($redirect) && $redirect !== '' ? $redirect : null;
        } else {
            $context = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", [...$headers, 'User-Agent: ' . $this->userAgent]),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ]]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                throw new OsmException('Verbindung fehlgeschlagen.');
            }
            $ip = '';
            $status = 0;
            $location = null;
            // PHP 8.4+ provides a function; the magic variable is deprecated as of 8.5.
            $headerLines = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : $http_response_header;
            foreach ($headerLines as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                } elseif (preg_match('#^Location:\s*(\S+)#i', $line, $m)) {
                    $location = $m[1];
                }
            }
        }

        $isRedirect = in_array($status, [301, 302, 303, 307, 308], true);
        return ['status' => $status, 'body' => $response, 'ip' => $ip, 'location' => $isRedirect ? $location : null];
    }

    /** @param array{status: int, body: string, ip: string, location: ?string} $response */
    private static function checked(array $response): string
    {
        ['status' => $status, 'body' => $body, 'ip' => $ip] = $response;
        if ($status === 429) {
            throw new OsmException('Der Dienst ist gerade überlastet (429). Bitte gleich nochmal versuchen.');
        }
        if ($status < 200 || $status >= 300) {
            // IP and the start of the error page tell whether the error came from the
            // service itself or from a proxy in between (e.g. at the hosting provider).
            $snippet = self::snippet($body);
            throw new OsmException("Der Dienst antwortet mit HTTP $status."
                . ($ip !== '' ? " [IP $ip]" : '')
                . ($snippet !== '' ? " „{$snippet}“" : ''));
        }
        return $body;
    }

    /** Absolute URL for a Location header (absolute, host-relative or path-relative). */
    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
        return $origin . $dir . $location;
    }

    private static function snippet(string $body): string
    {
        $text = (string) preg_replace(['#<(script|style)\b.*?</\1>#is', '/<[^>]*>/'], ' ', $body);
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text)));
        return mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '…' : $text;
    }
}
