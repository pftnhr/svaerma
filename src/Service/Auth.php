<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Session;
use App\Repository\LoginAttemptRepository;
use App\Repository\SettingsRepository;

/**
 * Single-user authentication.
 *
 * Brute-force protection: at most MAX_PER_IP failures per IP and MAX_GLOBAL failures
 * overall per WINDOW. Devices that logged in successfully before carry a "known device"
 * cookie and are exempt from the global limit, so strangers cannot lock the owner out.
 *
 * Every session is bound to a session epoch stored in the settings. Rotating the epoch
 * (password reset, "log out everywhere") invalidates all sessions and device cookies.
 */
final class Auth
{
    public const WINDOW = 15 * 60;
    public const MAX_PER_IP = 5;
    public const MAX_GLOBAL = 25;
    public const DEVICE_COOKIE = 'svaerma_device';
    private const DEVICE_LIFETIME = 60 * 60 * 24 * 365;

    public function __construct(
        private Session $session,
        private SettingsRepository $settings,
        private LoginAttemptRepository $attempts,
    ) {
    }

    public function check(): bool
    {
        if ($this->session->get('auth') !== true) {
            return false;
        }
        $mark = $this->session->get('epoch');
        return is_string($mark) && hash_equals($this->epochMark(), $mark);
    }

    /** Whether the request comes from a device that logged in successfully before. */
    public function knownDevice(): bool
    {
        $cookie = $_COOKIE[self::DEVICE_COOKIE] ?? null;
        return is_string($cookie) && hash_equals($this->deviceToken(), $cookie);
    }

    public function isLocked(string $ip, ?int $now = null, string $scope = LoginAttemptRepository::LOGIN, bool $knownDevice = false): bool
    {
        $since = ($now ?? time()) - self::WINDOW;
        if ($this->attempts->countSince($since, $ip, $scope) >= self::MAX_PER_IP) {
            return true;
        }
        return !$knownDevice && $this->attempts->countSince($since, null, $scope) >= self::MAX_GLOBAL;
    }

    /**
     * @return 'ok'|'invalid'|'locked'
     */
    public function attempt(string $password, string $ip, ?int $now = null, ?bool $knownDevice = null): string
    {
        $now ??= time();
        $this->attempts->purgeOlderThan($now - self::WINDOW);
        if ($this->isLocked($ip, $now, LoginAttemptRepository::LOGIN, $knownDevice ?? $this->knownDevice())) {
            return 'locked';
        }

        $hash = (string) $this->settings->get('password_hash', '');
        if ($hash === '' || !password_verify($password, $hash)) {
            $this->attempts->record($ip, $now);
            return 'invalid';
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->settings->set('password_hash', password_hash($password, PASSWORD_DEFAULT));
        }
        $this->attempts->clear($ip);
        $this->session->regenerate();
        $this->session->set('auth', true);
        $this->session->set('epoch', $this->epochMark());
        $this->session->setCookie(self::DEVICE_COOKIE, $this->deviceToken(), self::DEVICE_LIFETIME);
        return 'ok';
    }

    public function setPassword(string $password): void
    {
        $this->settings->set('password_hash', password_hash($password, PASSWORD_DEFAULT));
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    /** Ends every session on every device and forgets all known devices. */
    public function logoutEverywhere(): void
    {
        self::rotateEpoch($this->settings);
        $this->session->destroy();
        $this->session->purgeAll();
    }

    public static function rotateEpoch(SettingsRepository $settings): void
    {
        $settings->set('session_epoch', bin2hex(random_bytes(32)));
    }

    private function epoch(): string
    {
        $epoch = (string) $this->settings->get('session_epoch', '');
        if ($epoch === '') {
            // First run after the update: sessions from before have no epoch and end here.
            self::rotateEpoch($this->settings);
            $epoch = (string) $this->settings->get('session_epoch', '');
        }
        return $epoch;
    }

    /** What the session stores: derived from the epoch, so session files do not reveal it. */
    private function epochMark(): string
    {
        return hash_hmac('sha256', 'session', $this->epoch());
    }

    private function deviceToken(): string
    {
        return hash_hmac('sha256', 'known-device', $this->epoch());
    }
}
