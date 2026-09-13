<?php

declare(strict_types=1);

use Naf\Auth\Auth;
use Naf\Auth\Identity\Identity;
use Naf\Auth\Provider\{DatabaseProvider, ModelRepository, OrmProvider};
use Naf\Auth\Session\{SessionStateStore, StateStoreInterface};
use Naf\Auth\Support\PasswordHasher;
use Naf\ORM\Core\EntityInterface;
use Naf\ORM\Core\EntityManager;
use Naf\ORM\Repository\{AbstractRepository, RepositoryFactory};
use Naf\Session\Core\Session;
use function Naf\app;
use function Naf\config;

$container = app()->container();

// Factories run on first use. Application bindings take precedence.
if (!$container->has(PasswordHasher::class)) {
    $container->set(PasswordHasher::class, static fn() => new PasswordHasher());
}

if (!$container->has(SessionStateStore::class)) {
    $container->set(SessionStateStore::class, static fn() => new SessionStateStore(
        $container->get(Session::class),
    ));
}

if (!$container->has(DatabaseProvider::class)) {
    $container->set(DatabaseProvider::class, static fn() => new DatabaseProvider(
        connection: $container->get(PDO::class),
        hasher: $container->get(PasswordHasher::class),
        table: config('auth:database:table', 'users'),
        usernameField: config('auth:database:username_field', 'username'),
        passwordField: config('auth:database:password_field', 'password'),
        identifierField: config('auth:database:identifier_field', 'id'),
        identityFactory: config('auth:database:identity_factory')
            ?? static fn(array $row) => new Identity((string) $row[config('auth:database:identifier_field', 'id')]),
    ));
}

if (!$container->has(OrmProvider::class)) {
    $container->set(OrmProvider::class, static function () use ($container): OrmProvider {
        $repository = config('auth:orm:repository');
        $model      = config('auth:users:model');

        if (is_string($repository) && is_a($repository, AbstractRepository::class, true)) {
            // An application that wrote its own repository keeps using it.
            $resolved = $container->has($repository)
                ? $container->get($repository)
                : $container->get(RepositoryFactory::class)->create($repository);

            $fields = 'auth:orm:';
        } elseif (is_string($model) && is_a($model, EntityInterface::class, true)) {
            // The ordinary case: a model class, and nothing else to write.
            $resolved = new ModelRepository(
                $container->get(PDO::class),
                $container->get(EntityManager::class),
                $model,
            );

            $fields = 'auth:users:';
        } else {
            throw new InvalidArgumentException(
                'Configure auth:users:model with your user model, or auth:orm:repository with a repository class.'
            );
        }

        return new OrmProvider(
            repository: $resolved,
            hasher: $container->get(PasswordHasher::class),
            entityManager: $container->get(EntityManager::class),
            usernameField: config($fields . 'username_field', 'username'),
            passwordField: config($fields . 'password_field', 'password'),
            identifierField: config($fields . 'identifier_field', 'id'),
        );
    });
}

if (!$container->has(Auth::class)) {
    $container->set(Auth::class, static function () use ($container): Auth {
        $store = null;
        if ($container->has(StateStoreInterface::class)) {
            $store = $container->get(StateStoreInterface::class);
        } elseif (config('auth:session') !== false) {
            if (app()->hasPlugin('naf/session')) {
                $store = $container->get(SessionStateStore::class);
            } elseif (config('auth:session') === true) {
                throw new RuntimeException('auth:session is on, but naf/session is not installed. Run "composer require naf/session".');
            }
        }

        $auth = new Auth($store, static fn(string $class): object => $container->get($class));

        $providers = (array) config('auth:providers', []);

        // Naming sources explicitly wins; it is the only way to have several, and
        // an application that already did keeps the names it chose.
        if ($providers === [] && config('auth:users:model') !== null) {
            $providers = ['users' => match (config('auth:users:store', 'orm')) {
                'orm'   => OrmProvider::class,
                default => throw new RuntimeException(
                    'auth:users:store only understands "orm". For anything else, name the source in auth:providers.'
                ),
            }];
        }

        foreach ($providers as $name => $provider) {
            $auth->addProvider($name, $provider);
        }

        foreach (config('auth:policies', []) as $resource => $policy) {
            $auth->policy($resource, $policy);
        }

        return $auth;
    });
}
