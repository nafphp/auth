<?php

declare(strict_types=1);

return ['auth' => [
    // null persists when nixphp/session is installed; true requires it, false disables it.
    'session' => null,
    // Source name => provider service registered in the container.
    'providers' => [],
    // Resource class => policy callback.
    'policies' => [],
    'database' => [
        'table' => 'users',
        'username_field' => 'username',
        'password_field' => 'password',
        'identifier_field' => 'id',
        'identity_factory' => null,
    ],
    'orm' => [
        'repository' => null,
        'username_field' => 'username',
        'password_field' => 'password',
        'identifier_field' => 'id',
    ],
]];
