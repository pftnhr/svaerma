<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CacheRepository;
use App\Service\Osm\HttpClient;
use App\Service\Osm\OsmException;

/**
 * Asks GitHub for the latest published release (drafts and pre-releases are not
 * returned by that endpoint) at most once per CHECK_INTERVAL. Failures are silent:
 * no hint is better than an error on the settings page.
 */
final class UpdateCheck
{
    public const CHECK_INTERVAL = 86400;
    private const RETRY_AFTER_FAILURE = 6 * 3600;
    private const CACHE_KEY = 'update:latest-release';

    public function __construct(
        private HttpClient $http,
        private CacheRepository $cache,
        private string $repository,
        private string $currentVersion,
    ) {
    }

    /** @return array{version: string, url: string}|null a newer release, or null */
    public function newerRelease(): ?array
    {
        $latest = $this->cache->get(self::CACHE_KEY);
        if ($latest === null) {
            $latest = $this->fetch();
            $this->cache->set(self::CACHE_KEY, $latest, $latest === '' ? self::RETRY_AFTER_FAILURE : self::CHECK_INTERVAL);
        }
        if ($latest === '' || version_compare($latest, $this->currentVersion, '<=')) {
            return null;
        }
        // The link is built here, not taken from the response.
        return ['version' => $latest, 'url' => sprintf('https://github.com/%s/releases/tag/v%s', $this->repository, $latest)];
    }

    /** @return string version like "0.9.0", or "" when unknown */
    private function fetch(): string
    {
        try {
            $json = $this->http->get(sprintf('https://api.github.com/repos/%s/releases/latest', $this->repository));
        } catch (OsmException) {
            return '';
        }
        $data = json_decode($json, true);
        $tag = is_array($data) && is_string($data['tag_name'] ?? null) ? $data['tag_name'] : '';
        return preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $m) ? $m[1] : '';
    }
}
