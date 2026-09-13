<?php

declare(strict_types=1);

namespace Tests\Unit;

use NixPHP\Auth\Auth;
use NixPHP\Auth\Credentials\PasswordCredentials;
use NixPHP\Auth\Exceptions\{ForbiddenException, UnauthenticatedException};
use NixPHP\Auth\Identity\Identity;
use NixPHP\Auth\Provider\{DatabaseProvider, OrmProvider};
use NixPHP\Auth\Session\{SessionStateStore, StateStoreInterface};
use NixPHP\Auth\Support\PasswordHasher;
use NixPHP\Core\{Config, ErrorHandler};
use NixPHP\ORM\Core\EntityManager;
use NixPHP\ORM\Repository\RepositoryFactory;
use NixPHP\Session\Core\Session;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\{MemoryStore, ProviderSpy, User, UserRepository};
use function NixPHP\app;
use function NixPHP\Auth\auth;

final class WiringTest extends TestCase
{
    private array $original = [];
    private const SERVICES = [Auth::class, StateStoreInterface::class, SessionStateStore::class,
        PasswordHasher::class, DatabaseProvider::class, OrmProvider::class, PDO::class,
        Config::class, Session::class, ProviderSpy::class, EntityManager::class, RepositoryFactory::class];

    protected function setUp(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__) . '/Fixtures');
        }
        $container = app()->container();
        foreach ([Config::class, Session::class] as $service) {
            if ($container->has($service)) {
                $this->original[$service] = $container->get($service);
            }
        }
        foreach (self::SERVICES as $service) {
            $container->reset($service);
        }
        $this->configure(['session' => false]);
    }

    protected function tearDown(): void
    {
        $container = app()->container();
        foreach (self::SERVICES as $service) {
            $container->reset($service);
        }
        foreach ($this->original as $service => $instance) {
            $container->set($service, $instance);
        }
    }

    private function configure(array $auth): void
    {
        app()->container()->set(Config::class, new Config(['auth' => $auth]));
    }

    private function boot(): void
    {
        require dirname(__DIR__, 2) . '/bootstrap.php';
    }

    public function testHelperRequiresBootstrapAndDoesNotRegisterAnything(): void
    {
        try {
            auth();
            self::fail('The helper must not wire the plugin implicitly.');
        } catch (\Psr\Container\NotFoundExceptionInterface) {
            self::assertFalse(app()->container()->has(Auth::class));
        }
        $this->boot();
        self::assertSame(auth(), app()->container()->get(Auth::class));
    }

    public function testBootstrapIsLazyAndRepeatedBootKeepsRegistrations(): void
    {
        $calls = 0;
        $container = app()->container();
        $container->set(ProviderSpy::class, static function () use (&$calls) {
            $calls++;
            return new ProviderSpy(new Identity('42'));
        });
        $this->configure(['session' => false, 'providers' => ['custom' => ProviderSpy::class]]);
        $this->boot();
        $auth = auth();
        self::assertTrue($auth->hasProvider('custom'));
        self::assertSame(0, $calls);
        $this->boot();
        self::assertSame($auth, auth());
        self::assertTrue(auth()->authenticate(new PasswordCredentials('alice', 'valid')));
        self::assertTrue(auth()->authenticate(new PasswordCredentials('alice', 'valid')));
        self::assertSame(1, $calls);
    }

    public function testUnregisteredProvidersAreNotImplicitlyConstructed(): void
    {
        $this->configure(['session' => false, 'providers' => ['custom' => ProviderSpy::class]]);
        $this->boot();
        $this->expectException(\Psr\Container\NotFoundExceptionInterface::class);
        auth()->authenticate(new PasswordCredentials('alice', 'valid'));
    }

    public function testPoliciesAreRegisteredFromConfiguration(): void
    {
        $this->configure(['session' => false, 'policies' => [\stdClass::class =>
            static fn($user, $action, $post): bool => $action === 'edit' && $post->owner === $user->getIdentifier(),
        ]]);
        $this->boot();
        auth()->setIdentity(new Identity('42'));
        self::assertTrue(auth()->allows('edit', (object) ['owner' => '42']));
        self::assertFalse(auth()->allows('edit', (object) ['owner' => 'other']));
    }

    public function testCustomStoreAndHasherBindingsWin(): void
    {
        $store = new MemoryStore();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
        app()->container()->set(StateStoreInterface::class, $store);
        app()->container()->set(PasswordHasher::class, $hasher);
        $this->boot();
        auth()->addProvider('custom', new ProviderSpy(new Identity('42')));
        auth()->authenticate(new PasswordCredentials('alice', 'valid'));
        self::assertSame(['provider' => 'custom', 'identifier' => '42'], $store->record);
        self::assertSame($hasher, app()->container()->get(PasswordHasher::class));
    }

    public function testSessionIsInjectedOnlyWhenPersistenceIsNeeded(): void
    {
        $calls = 0;
        app()->container()->set(Session::class, static function () use (&$calls): Session {
            $calls++;
            return new Session();
        });
        $this->boot();
        auth();
        self::assertSame(0, $calls);
        app()->container()->reset(Auth::class);
        $this->configure(['session' => null]);
        $this->boot();
        self::assertSame(0, $calls);
        auth();
        self::assertSame(1, $calls);
        self::assertInstanceOf(SessionStateStore::class, app()->container()->get(SessionStateStore::class));
    }

    public function testDatabaseProviderUsesConfiguredConnectionColumnsMapperAndHasher(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE accounts (account_id INTEGER PRIMARY KEY, email TEXT, hash TEXT)');
        $pdo->prepare('INSERT INTO accounts VALUES (7, ?, ?)')->execute(['alice', password_hash('correct', PASSWORD_BCRYPT, ['cost' => 4])]);
        $container = app()->container();
        $container->set(PDO::class, $pdo);
        $container->set(PasswordHasher::class, new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 5]));
        $container->set(StateStoreInterface::class, new MemoryStore());
        $this->configure(['providers' => ['database' => DatabaseProvider::class], 'database' => [
            'table' => 'accounts', 'identifier_field' => 'account_id', 'username_field' => 'email', 'password_field' => 'hash',
            'identity_factory' => static fn(array $row) => new Identity((string) $row['account_id'], ['editor']),
        ]]);
        $this->boot();
        self::assertTrue(auth()->authenticate(new PasswordCredentials('alice', 'correct')));
        self::assertTrue(auth()->hasRole('editor'));
        self::assertSame(5, password_get_info($pdo->query('SELECT hash FROM accounts')->fetchColumn())['options']['cost']);
        auth()->reset();
        self::assertSame('7', auth()->id());
    }

    public function testDefaultIdentityFactoryUsesTheConfiguredIdentifierColumn(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE accounts (account_id TEXT PRIMARY KEY, username TEXT, password TEXT)");
        $pdo->exec("INSERT INTO accounts VALUES ('opaque-id', 'alice', NULL)");
        $this->configure(['session' => false, 'providers' => ['pdo' => DatabaseProvider::class],
            'database' => ['table' => 'accounts', 'identifier_field' => 'account_id'],
        ]);
        $this->boot();
        // The connection may be registered after bootstrap and manager construction.
        self::assertTrue(auth()->hasProvider('pdo'));
        app()->container()->set(PDO::class, $pdo);
        $identity = app()->container()->get(DatabaseProvider::class)->find('opaque-id');
        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame('opaque-id', $identity->getIdentifier());
        self::assertSame([], $identity->getRoles());
    }

    public function testOrmProviderReceivesRepositoryAndEntityManagerFromBootstrap(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, roles TEXT)");
        $pdo->prepare("INSERT INTO users VALUES (7, ?, ?, 'editor')")->execute(['alice', password_hash('correct', PASSWORD_BCRYPT, ['cost' => 4])]);
        $manager = new EntityManager($pdo);
        $container = app()->container();
        $container->set(EntityManager::class, $manager);
        $container->set(RepositoryFactory::class, new RepositoryFactory($pdo, $manager));
        $container->set(PasswordHasher::class, new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]));
        $this->configure(['session' => false, 'providers' => ['orm' => OrmProvider::class], 'orm' => ['repository' => UserRepository::class]]);
        $this->boot();
        self::assertTrue(auth()->authenticate(new PasswordCredentials('alice', 'correct')));
        self::assertInstanceOf(User::class, auth()->user());
    }

    public function testFailuresCarryTheirHttpStatus(): void
    {
        self::assertSame(401, ErrorHandler::resolveStatusCode(new UnauthenticatedException()));
        self::assertSame(403, ErrorHandler::resolveStatusCode(new ForbiddenException()));
    }

    public function testOneModelIsEnoughToRegisterASource(): void
    {
        // No repository class, no identity factory, no provider name.
        $this->configure(['session' => false, 'users' => ['model' => User::class]]);
        $this->boot();

        self::assertTrue(auth()->hasProvider('users'));
        self::assertSame(['users'], auth()->providers());
    }

    public function testNamingSourcesExplicitlyStillWins(): void
    {
        // An application that already named its sources keeps the names it chose.
        $this->configure([
            'session'   => false,
            'users'     => ['model' => User::class],
            'providers' => ['database' => ProviderSpy::class],
        ]);
        $this->boot();

        self::assertSame(['database'], auth()->providers());
    }

    public function testWithoutEitherThereIsNoSource(): void
    {
        $this->configure(['session' => false]);
        $this->boot();

        self::assertSame([], auth()->providers());
    }
}
