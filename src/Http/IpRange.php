<?php

declare(strict_types=1);

namespace App\Http;

/**
 * IP address helpers: CIDR matching (trusted proxies) and "is this a public address"
 * (outgoing requests to user-supplied URLs must not reach internal networks).
 */
final class IpRange
{
    /** Private, loopback and link-local networks: a reverse proxy on the same host or LAN. */
    public const PRIVATE_NETWORKS = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7'];

    /** @param list<string> $ranges IPs or CIDR ranges */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    public static function matches(string $ip, string $range): bool
    {
        [$net, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** Whether an IP is globally routable (not private, loopback, link-local, reserved). */
    public static function isPublic(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) is checked as IPv4.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !self::matchesAny($ip, ['100.64.0.0/10', '0.0.0.0/8', '169.254.0.0/16', 'fe80::/10', 'fc00::/7', '::/128', '::1/128']);
    }

    /**
     * Resolves a host name and checks that every address is public.
     * Fails closed: unresolvable hosts count as not public.
     */
    public static function hostIsPublic(string $host): bool
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublic($host);
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) {
            $v4 = @gethostbynamel($host);
            $records = is_array($v4) ? array_map(static fn (string $ip): array => ['ip' => $ip], $v4) : [];
        }
        $ips = [];
        foreach ($records as $r) {
            if (isset($r['ip'])) {
                $ips[] = (string) $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                return false;
            }
        }
        return true;
    }
}
