<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Thin wrapper around PHP sessions with hardened cookie settings.
 * Session files live in data/sessions so shared-hosting GC does not wipe them early.
 *
 * Sessions start lazily: reading from a request without a session cookie returns
 * defaults without creating a file, so visitors, bots and 404s leave nothing behind.
 */
final class Session
{
    private const LIFETIME = 60 * 60 * 24 * 30; // 30 days – personal app on a phone

    private bool $started = false;

    public function __construct(
        private string $savePath,
        private bool $secure,
    ) {
    }

    /** "__Host-" makes the browser enforce Secure, Path=/ and no Domain (HTTPS only). */
    public function cookieName(): string
    {
        return $this->secure ? '__Host-svaerma_sid' : 'svaerma_sid';
    }

    /** Whether the request carries a session cookie (i.e. there may be data to read). */
    public function exists(): bool
    {
        return $this->started || isset($_COOKIE[$this->cookieName()]);
    }

    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            $this->started = true;
            return;
        }
        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0700, true);
        }
        session_save_path($this->savePath);
        session_name($this->cookieName());
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::LIFETIME);
        // Some distributions disable GC (probability 0) and rely on a cron job that
        // only knows the default path – clean up data/sessions ourselves.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_set_cookie_params($this->cookieParams(self::LIFETIME));
        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->exists()) {
            return $default;
        }
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        if (!$this->exists()) {
            return;
        }
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (!$this->exists()) {
            return;
        }
        $this->start();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli') {
            $params = $this->cookieParams(0);
            unset($params['lifetime']);
            setcookie($this->cookieName(), '', ['expires' => time() - 3600] + $params);
            session_destroy();
        }
        $this->started = false;
    }

    /**
     * Deletes every stored session (all devices are logged out), e.g. after a password reset.
     *
     * @return int number of removed session files
     */
    public static function purgeDirectory(string $savePath): int
    {
        $removed = 0;
        foreach (glob(rtrim($savePath, '/') . '/sess_*') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Logs out every device: the current session and all stored ones. */
    public function purgeAll(): int
    {
        return self::purgeDirectory($this->savePath);
    }

    /** Sets an extra cookie with the same hardening as the session cookie. */
    public function setCookie(string $name, string $value, int $lifetime): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $params = $this->cookieParams($lifetime);
        unset($params['lifetime']);
        setcookie($name, $value, ['expires' => time() + $lifetime] + $params);
    }

    public function flash(string $type, string $message): void
    {
        $messages = $this->get('_flash', []);
        $messages[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $messages);
    }

    /** @return list<array{type: string, message: string}> */
    public function takeFlash(): array
    {
        $messages = $this->get('_flash', []);
        if ($messages !== []) {
            $this->remove('_flash');
        }
        return $messages;
    }

    public function csrfToken(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->set('_csrf', $token);
        }
        return $token;
    }

    public function validCsrf(string $token): bool
    {
        $expected = $this->get('_csrf');
        return is_string($expected) && $token !== '' && hash_equals($expected, $token);
    }

    /** @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string} */
    private function cookieParams(int $lifetime): array
    {
        return [
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
