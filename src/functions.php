<?php

declare(strict_types=1);

namespace Naf\Auth;

use function Naf\app;

/** The shared manager registered by the plugin bootstrap. */
function auth(): Auth
{
    return app()->container()->get(Auth::class);
}
