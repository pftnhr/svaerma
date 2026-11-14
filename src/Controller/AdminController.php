<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Repository\CacheRepository;
use App\Service\Osm\OsmException;
use App\Service\Osm\Overpass;

final class AdminController extends Controller
{
    /**
     * Admin and settings live on one page now; keep old links working.
     *
     * @param array<string, string> $params
     */
    public function index(array $params): Response
    {
        return $this->redirect('/settings', 301);
    }

    /** @param array<string, string> $params */
    public function migrate(array $params): Response
    {
        try {
            $result = $this->app->migrator()->migrate();
            if ($result['applied'] === []) {
                $this->flash('info', 'Keine ausstehenden Migrationen.');
            } else {
                $msg = 'Migriert: ' . implode(', ', $result['applied']) . '.';
                if ($result['backup'] !== null) {
                    $msg .= ' Backup: ' . basename($result['backup']);
                }
                $this->flash('success', $msg);
            }
        } catch (\Throwable $e) {
            $this->app->log($e);
            $this->flash('danger', 'Migration fehlgeschlagen, Änderungen wurden zurückgerollt. Details stehen in data/logs/error.log.');
        }
        return $this->redirect(SettingsController::sectionUrl('system'));
    }

    /** @param array<string, string> $params */
    public function backup(array $params): Response
    {
        $file = $this->app->backup()->create('manual');
        $this->flash('success', 'Backup erstellt: ' . basename($file));
        return $this->redirect(SettingsController::sectionUrl('system'));
    }

    /**
     * Connectivity check for all OSM endpoints, bypassing the cache.
     *
     * @param array<string, string> $params
     */
    public function osmTest(array $params): Response
    {
        $http = $this->app->http((int) $this->app->config->get('osm.overpass_timeout'));
        $checks = [];
        foreach ($this->app->overpassEndpoints() as $endpoint) {
            $checks['Overpass ' . $endpoint['label']] = function () use ($http, $endpoint): int {
                $overpass = new Overpass($http, new CacheRepository($this->app->pdo()), [$endpoint], 0);
                $json = $overpass->fetch(Overpass::query(52.5163, 13.3777, 100));
                return count(Overpass::parse(json_decode($json, true)['elements'] ?? [], 52.5163, 13.3777));
            };
        }
        $checks['Photon-Umkreissuche (Fallback)'] = function (): int {
            return count($this->app->placeSearch(cacheTtl: 0)->nearby(52.5163, 13.3777, 100));
        };
        $geoapify = $this->app->geoapify();
        if ($geoapify !== null) {
            $checks['Geoapify'] = static fn (): int => count($geoapify->nearby(52.5163, 13.3777, 100));
        }
        $provider = (string) $this->app->config->get('osm.search_provider');
        $checks['Suche (' . $provider . ')'] = function () use ($http, $provider): void {
            $body = $provider === 'nominatim'
                ? $http->get((string) $this->app->config->get('osm.nominatim_url'), ['q' => 'Berlin', 'format' => 'jsonv2', 'limit' => 1])
                : $http->get((string) $this->app->config->get('osm.photon_url'), ['q' => 'Berlin', 'limit' => 1]);
            if (!is_array(json_decode($body, true))) {
                throw new OsmException('ungültige Antwort');
            }
        };

        foreach ($checks as $name => $check) {
            $start = microtime(true);
            try {
                $found = $check();
                $this->flash('success', sprintf(
                    '%s: OK%s (%d ms)',
                    $name,
                    is_int($found) ? ", $found Orte am Brandenburger Tor" : '',
                    (microtime(true) - $start) * 1000,
                ));
            } catch (OsmException $e) {
                $this->flash('danger', sprintf('%s: %s (%d ms)', $name, $e->getMessage(), (microtime(true) - $start) * 1000));
            }
        }
        return $this->redirect(SettingsController::sectionUrl('system'));
    }
}
