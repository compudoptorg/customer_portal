<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // The registration site (sonar-registration) auto-submits a login form here from another domain,
        // so it cannot carry our CSRF token. The handler only ever redirects to fixed portal pages.
        'signup-login',
    ];
}
