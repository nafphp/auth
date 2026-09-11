<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use LogicException;
use NixPHP\Auth\Auth;
use NixPHP\Auth\Credentials\PasswordCredentials;
use NixPHP\Auth\Identity\{Identity, IdentityInterface};
use NixPHP\Auth\Provider\DatabaseProvider;
use NixPHP\Auth\Support\PasswordHasher;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\MemoryStore;

final class DatabaseProviderTest extends TestCase
{
    private PDO $pdo;
    private PasswordHasher $hasher;
    private \Closure $identityFactory;

    protected function setUp(): void
    {
        $this->identityFactory = static fn(array $row) => new Identity((string) $row['id']);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, enabled INTEGER DEFAULT 1)');
        $this->hasher = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $statement = $this->pdo->prepare('INSERT INTO users (id, username, password) VALUES (7, ?, ?)');
        $statement->execute(['alice', $this->hasher->hash('correct')]);
    }

    public function testAuthenticatesUsingOnlyPdoAndTheExistingPasswordVerifier(): void
    {
        $provider = new DatabaseProvider($this->pdo, $this->hasher, $this->identityFactory);
        $identity = $provider->authenticate(new PasswordCredentials('alice', 'correct'));
        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame('7', $identity->getIdentifier());
        self::assertSame([], $identity->getRoles());
        self::assertSame([], $identity->getPermissions());
    }

    public function testWrongUnknownAndMissingPasswordsAreDenied(): void
    {
        $provider = new DatabaseProvider($this->pdo, $this->hasher, $this->identityFactory);
        foreach ([['alice', 'wrong'], ['unknown', 'correct'], ['', 'correct'], ["alice' OR 1=1 --", 'correct']] as [$name, $password]) {
            self::assertNull($provider->authenticate(new PasswordCredentials($name, $password)));
        }
        $this->pdo->exec('UPDATE users SET password = NULL');
        self::assertNull($provider->authenticate(new PasswordCredentials('alice', '')));
    }

    public function testFindReloadsAndDeletedAccountsClearAuthentication(): void
    {
        $store = new MemoryStore();
        $provider = new DatabaseProvider($this->pdo, $this->hasher, $this->identityFactory);
        $auth = new Auth($store);
        $auth->addProvider('pdo', $provider);
        self::assertTrue($auth->authenticate(new PasswordCredentials('alice', 'correct')));
        self::assertSame(['provider' => 'pdo', 'identifier' => '7'], $store->record);
        $auth->reset();
        self::assertSame('7', $auth->id());
        self::assertSame('7', $provider->find('7')->getIdentifier());
        self::assertNull($provider->find('999'));
        self::assertNull($provider->find(''));
        self::assertNull($provider->find('7 OR 1=1'));
        $this->pdo->exec('DELETE FROM users');
        $auth->reset();
        self::assertFalse($auth->check());
        self::assertNull($store->record);
    }

    public function testFactoryReturnsYourModelWithoutReceivingThePasswordHash(): void
    {
        $provider = new DatabaseProvider($this->pdo, identityFactory: static function (array $row): IdentityInterface {
            self::assertArrayNotHasKey('password', $row);
            return new PdoUser((string) $row['id'], $row['username']);
        }, hasher: $this->hasher);
        $auth = new Auth();
        $auth->addProvider('pdo', $provider);
        self::assertTrue($auth->authenticate(new PasswordCredentials('alice', 'correct')));
        self::assertInstanceOf(PdoUser::class, $auth->user());
        self::assertSame('alice', $auth->user()->username);
        self::assertTrue($auth->hasRole('editor'));
        self::assertTrue($auth->can('articles.edit'));
    }

    public function testFactoryCanExcludeDisabledAccountsFromLoginAndRestore(): void
    {
        $provider = new DatabaseProvider($this->pdo, identityFactory: static fn(array $row): ?IdentityInterface =>
            (int) $row['enabled'] === 1 ? new Identity((string) $row['id']) : null, hasher: $this->hasher);
        self::assertNotNull($provider->find('7'));
        $this->pdo->exec('UPDATE users SET enabled = 0');
        self::assertNull($provider->find('7'));
        self::assertNull($provider->authenticate(new PasswordCredentials('alice', 'correct')));
    }

    public function testMappingCannotChangeTheAccountIdentifier(): void
    {
        $provider = new DatabaseProvider($this->pdo, hasher: $this->hasher, identityFactory: static fn(array $row) => new Identity('another-account'));
        $this->expectException(LogicException::class);
        $provider->find('7');
    }

    public function testMappingMustReturnAnIdentity(): void
    {
        $provider = new DatabaseProvider($this->pdo, hasher: $this->hasher, identityFactory: static fn(array $row) => new \stdClass());
        $this->expectException(LogicException::class);
        $provider->find('7');
    }

    public function testTableAndColumnNamesAreConfigurableAndQuoted(): void
    {
        $this->pdo->exec('CREATE TABLE "order" ("key" TEXT PRIMARY KEY, email TEXT UNIQUE, hash TEXT)');
        $this->pdo->prepare('INSERT INTO "order" VALUES (?, ?, ?)')->execute(['opaque-id', 'a@example.test', $this->hasher->hash('correct')]);
        $provider = new DatabaseProvider($this->pdo, identityFactory: static fn(array $row) => new Identity((string) $row['key']), table: 'order', usernameField: 'email', passwordField: 'hash', identifierField: 'key', hasher: $this->hasher);
        self::assertSame('opaque-id', $provider->authenticate(new PasswordCredentials('a@example.test', 'correct'))->getIdentifier());
        self::assertSame('opaque-id', $provider->find('opaque-id')->getIdentifier());
    }

    public function testUnsafeIdentifiersAreRejectedBeforeQuerying(): void
    {
        foreach (['table', 'usernameField', 'passwordField', 'identifierField'] as $parameter) {
            try {
                new DatabaseProvider($this->pdo, $this->hasher, $this->identityFactory, ...[$parameter => 'id; DROP TABLE users']);
                self::fail('Unsafe SQL identifier accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testAmbiguousUsernamesFailInsteadOfSelectingAnArbitraryAccount(): void
    {
        $this->pdo->prepare('INSERT INTO users VALUES (8, ?, ?, 1)')->execute(['alice', $this->hasher->hash('other')]);
        $provider = new DatabaseProvider($this->pdo, $this->hasher, $this->identityFactory);
        $this->expectException(LogicException::class);
        $provider->authenticate(new PasswordCredentials('alice', 'correct'));
    }

    public function testRehashOnlyUpdatesAfterSuccessfulVerification(): void
    {
        $before = $this->pdo->query('SELECT password FROM users')->fetchColumn();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 5]);
        $provider = new DatabaseProvider($this->pdo, $hasher, $this->identityFactory);
        self::assertNull($provider->authenticate(new PasswordCredentials('alice', 'wrong')));
        self::assertSame($before, $this->pdo->query('SELECT password FROM users')->fetchColumn());
        self::assertNotNull($provider->authenticate(new PasswordCredentials('alice', 'correct')));
        $after = $this->pdo->query('SELECT password FROM users')->fetchColumn();
        self::assertNotSame($before, $after);
        self::assertTrue(password_verify('correct', $after));
        self::assertFalse($hasher->needsRehash($after));
    }

    public function testRehashDoesNotOverwriteAConcurrentPasswordReset(): void
    {
        $resetHash = $this->hasher->hash('reset-password');
        $provider = new DatabaseProvider($this->pdo, identityFactory: function (array $row) use ($resetHash): IdentityInterface {
            $this->pdo->prepare('UPDATE users SET password = ? WHERE id = 7')->execute([$resetHash]);
            return new Identity((string) $row['id']);
        }, hasher: new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 5]));
        self::assertNotNull($provider->authenticate(new PasswordCredentials('alice', 'correct')));
        self::assertSame($resetHash, $this->pdo->query('SELECT password FROM users')->fetchColumn());
    }

    public function testPdoSilentErrorsAreNotTreatedAsSuccessfulOperations(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $provider = new DatabaseProvider($this->pdo, $this->hasher, $this->identityFactory, table: 'missing');
        $this->expectException(RuntimeException::class);
        $provider->find('7');
    }
}

final readonly class PdoUser implements IdentityInterface
{
    public function __construct(private string $id, public string $username) {}
    public function getIdentifier(): string { return $this->id; }
    public function getRoles(): iterable { return ['editor']; }
    public function getPermissions(): iterable { return ['articles.edit']; }
}
