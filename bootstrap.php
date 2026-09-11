<?php

declare(strict_types=1);

use NixPHP\Auth\Auth;
use NixPHP\Auth\Identity\Identity;
use NixPHP\Auth\Provider\{DatabaseProvider, OrmProvider};
use NixPHP\Auth\Session\{SessionStateStore, StateStoreInterface};
use NixPHP\Auth\Support\PasswordHasher;
use NixPHP\ORM\Core\EntityManager;
use NixPHP\ORM\Repository\{AbstractRepository, RepositoryFactory};
use NixPHP\Session\Core\Session;
use function NixPHP\app;
use function NixPHP\config;

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
        if (!is_string($repository) || !is_a($repository, AbstractRepository::class, true)) {
            throw new InvalidArgumentException('Configure auth:orm:repository with an AbstractRepository class.');
        }

        return new OrmProvider(
            repository: $container->has($repository)
                ? $container->get($repository)
                : $container->get(RepositoryFactory::class)->create($repository),
            hasher: $container->get(PasswordHasher::class),
            entityManager: $container->get(EntityManager::class),
            usernameField: config('auth:orm:username_field', 'username'),
            passwordField: config('auth:orm:password_field', 'password'),
            identifierField: config('auth:orm:identifier_field', 'id'),
        );
    });
}

if (!$container->has(Auth::class)) {
    $container->set(Auth::class, static function () use ($container): Auth {
        $store = null;
        if ($container->has(StateStoreInterface::class)) {
            $store = $container->get(StateStoreInterface::class);
        } elseif (config('auth:session') !== false) {
            if (app()->hasPlugin('nixphp/session')) {
                $store = $container->get(SessionStateStore::class);
            } elseif (config('auth:session') === true) {
                throw new RuntimeException('auth:session is on, but nixphp/session is not installed. Run "composer require nixphp/session".');
            }
        }

        $auth = new Auth($store, static fn(string $class): object => $container->get($class));

        foreach (config('auth:providers', []) as $name => $provider) {
            $auth->addProvider($name, $provider);
        }

        foreach (config('auth:policies', []) as $resource => $policy) {
            $auth->policy($resource, $policy);
        }

        return $auth;
    });
}
