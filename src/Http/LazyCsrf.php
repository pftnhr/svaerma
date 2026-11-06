<?php

declare(strict_types=1);

namespace App\Http;

/**
 * CSRF token for templates that is only created when a template prints it,
 * so pages without forms do not start a session.
 */
final class LazyCsrf implements \Stringable
{
    public function __construct(private Session $session)
    {
    }

    public function __toString(): string
    {
        return $this->session->csrfToken();
    }
}
