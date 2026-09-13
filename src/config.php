<?php

declare(strict_types=1);

return ['auth' => [
    // null persists when naf/session is installed; true requires it, false disables it.
    'session' => null,

    /*
     * Where your accounts live. The ordinary case is one model:
     *
     *   'users' => ['model' => App\Models\User::class],
     *
     * That is the whole of it. The repository, the connection and the field names
     * are found or defaulted; the model implementing UserInterface is what makes
     * it work. The source is registered under the name "users", which is what
     * ends up in the session record and in account links.
     */
    'users' => [
        'model' => null,

        // 'orm' uses naf/orm. Anything else needs auth:providers below.
        'store' => 'orm',

        'username_field'   => 'username',
        'password_field'   => 'password',
        'identifier_field' => 'id',
    ],

    /*
     * The older, explicit form: source name => provider service in the container.
     * Still supported, and it takes precedence when both are configured — an
     * application that already names its sources keeps the names it chose.
     */
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
