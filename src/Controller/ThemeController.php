<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Service\Themes;

/**
 * Serves files of user themes from data/themes/<slug>/. Only CSS, woff2 and PNG,
 * only from inside the theme folder (see Themes::path()).
 */
final class ThemeController extends Controller
{
    /** @param array<string, string> $params */
    public function asset(array $params): Response
    {
        $themes = $this->app->themes();
        $path = $themes->path($params['slug'], $params['file']);
        $type = Themes::type($params['file']);
        if ($path === null || $type === null) {
            return $this->notFound();
        }
        $body = file_get_contents($path);
        if ($body === false) {
            return $this->notFound();
        }
        return new Response($body, 200, [
            'Content-Type' => $type,
            // theme.css and map.css are linked with ?v=<mtime>; fonts and images change rarely.
            'Cache-Control' => 'public, max-age=86400',
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT',
        ]);
    }
}
