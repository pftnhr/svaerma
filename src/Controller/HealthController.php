<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;

/**
 * Health check for the deploy workflow: answers 200 as soon as PHP runs the app.
 * Public, no session, no version or other details.
 */
final class HealthController extends Controller
{
    /** @param array<string, string> $params */
    public function check(array $params): Response
    {
        return new Response("ok\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
