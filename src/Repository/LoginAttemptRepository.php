<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Failed authentication attempts, kept separately per scope: "login" (password form)
 * and "api" (bearer tokens), so failures in one cannot lock the other.
 * The scope is stored as a prefix of the ip column ("api:1.2.3.4"); login rows have none.
 */
final class LoginAttemptRepository
{
    public const LOGIN = 'login';
    public const API = 'api';

    public function __construct(private PDO $pdo)
    {
    }

    public function record(string $ip, int $time, string $scope = self::LOGIN): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)');
        $stmt->execute([self::key($ip, $scope), $time]);
    }

    /** Failures since $since, for one IP or (with $ip = null) for all IPs of the scope. */
    public function countSince(int $since, ?string $ip = null, string $scope = self::LOGIN): int
    {
        if ($ip === null) {
            $op = $scope === self::LOGIN ? 'NOT LIKE' : 'LIKE';
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= ? AND ip $op 'api:%'");
            $stmt->execute([$since]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
            $stmt->execute([self::key($ip, $scope), $since]);
        }
        return (int) $stmt->fetchColumn();
    }

    public function clear(string $ip, string $scope = self::LOGIN): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE ip = ?');
        $stmt->execute([self::key($ip, $scope)]);
    }

    public function purgeOlderThan(int $time): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        $stmt->execute([$time]);
    }

    private static function key(string $ip, string $scope): string
    {
        return $scope === self::LOGIN ? $ip : $scope . ':' . $ip;
    }
}
