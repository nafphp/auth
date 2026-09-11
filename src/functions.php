<?php

declare(strict_types=1);

namespace NixPHP\Auth;

use function NixPHP\app;

/** The shared manager registered by the plugin bootstrap. */
function auth(): Auth
{
    return app()->container()->get(Auth::class);
}
