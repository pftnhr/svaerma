<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Http\Response;

/**
 * Service worker, rendered by PHP so its cache name follows the app version:
 * every update installs a fresh cache and removes the old one.
 */
final class PwaController extends Controller
{
    /** @param array<string, string> $params */
    public function serviceWorker(array $params): Response
    {
        $precache = [
            '/offline.html',
            '/manifest.webmanifest',
            '/assets/icons/icon-192.png',
            '/assets/icons/apple-touch-icon.png',
            asset('css/core.css'),
            '/assets/vendor/bricolage-grotesque-5.2.10/latin.woff2',
            '/assets/vendor/bricolage-grotesque-5.2.10/latin-ext.woff2',
            asset('js/theme.js'),
            asset('js/app.js'),
            asset('js/checkin.js'),
            asset('js/map.js'),
        ];
        $js = str_replace(
            ['__CACHE__', '__PRECACHE__'],
            ['svaerma-' . App::VERSION, json_encode($precache, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            (string) file_get_contents(APP_ROOT . '/templates/pwa/sw.js'),
        );
        return new Response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
