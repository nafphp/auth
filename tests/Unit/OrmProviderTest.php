<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use NixPHP\Auth\Auth;
use NixPHP\Auth\Credentials\PasswordCredentials;
use NixPHP\Auth\Provider\OrmProvider;
use NixPHP\Auth\Support\PasswordHasher;
use NixPHP\ORM\Core\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\{User, UserRepository, PlainUser, PlainUserRepository, StrangerUserRepository};

/** The shipped provider, against a real SQLite database. */
final class OrmProviderTest extends TestCase
{
    private PDO $pdo;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__) . '/Fixtures');
        }

        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                password TEXT NOT NULL,
                roles TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $this->entityManager = new EntityManager($this->pdo);
    }

    public function testTheRightPasswordReturnsYourOwnModel(): void
    {
        $id = $this->insert('alice', 'hunter2', 'admin,editor');

        $identity = $this->provider()->authenticate(new PasswordCredentials('alice', 'hunter2'));

        self::assertInstanceOf(User::class, $identity);
        self::assertSame($id, $identity->getIdentifier());
        self::assertSame('alice', $identity->getUsername());
        self::assertSame(['admin', 'editor'], iterator_to_array($this->iterate($identity->getRoles())));
    }

    public function testAWrongPasswordAndAnUnknownNameBothFail(): void
    {
        $this->insert('alice', 'hunter2');
        $provider = $this->provider();

        self::assertNull($provider->authenticate(new PasswordCredentials('alice', 'wrong')));
        self::assertNull($provider->authenticate(new PasswordCredentials('mallory', 'hunter2')));
        self::assertNull($provider->authenticate(new PasswordCredentials('', 'hunter2')));
    }

    public function testTheNextRequestReloadsTheAccountByItsIdentifier(): void
    {
        $id = $this->insert('alice', 'hunter2');
        $provider = $this->provider();

        self::assertInstanceOf(User::class, $provider->find($id));
        self::assertNull($provider->find('999'));
        self::assertNull($provider->find(''));
    }

    public function testADeletedAccountIsAGuestOnItsNextRequest(): void
    {
        $id = $this->insert('alice', 'hunter2');
        $auth = new Auth();
        $auth->addProvider('database', $this->provider());
        self::assertTrue($auth->authenticate(new PasswordCredentials('alice', 'hunter2')));

        $this->pdo->exec('DELETE FROM users WHERE id = ' . (int) $id);
        $auth->reset();

        self::assertNull($auth->user());
    }

    public function testAModelWithoutGettersIsReadFromTheOrmFieldMap(): void
    {
        $this->insert('alice', 'hunter2');
        $provider = new OrmProvider(
            new PlainUserRepository($this->pdo, $this->entityManager),
            entityManager: $this->entityManager,
            hasher: new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]),
        );

        self::assertInstanceOf(PlainUser::class, $provider->authenticate(new PasswordCredentials('alice', 'hunter2')));
    }

    public function testAModelThatIsNoIdentitySaysSoInsteadOfFailingToLogIn(): void
    {
        $this->insert('alice', 'hunter2');
        $provider = new OrmProvider(new StrangerUserRepository($this->pdo, $this->entityManager), new PasswordHasher(), $this->entityManager);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('IdentityInterface');
        $provider->find('1');
    }

    public function testAnOutdatedHashIsUpgradedInTheDatabase(): void
    {
        $id = $this->insert('alice', 'hunter2', cost: 4);
        $before = $this->storedHash($id);

        $provider = new OrmProvider(
            new UserRepository($this->pdo, $this->entityManager),
            hasher: new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 6]),
            entityManager: $this->entityManager,
        );

        self::assertNotNull($provider->authenticate(new PasswordCredentials('alice', 'hunter2')));

        $after = $this->storedHash($id);
        self::assertNotSame($before, $after, 'The upgraded hash must reach the database.');
        self::assertTrue(password_verify('hunter2', $after));
    }

    private function provider(): OrmProvider
    {
        return new OrmProvider(
            new UserRepository($this->pdo, $this->entityManager),
            hasher: new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]),
            entityManager: $this->entityManager,
        );
    }

    private function insert(string $username, string $password, string $roles = '', int $cost = 4): string
    {
        $statement = $this->pdo->prepare('INSERT INTO users (username, password, roles) VALUES (?, ?, ?)');
        $statement->execute([
            $username,
            (new PasswordHasher(PASSWORD_BCRYPT, ['cost' => $cost]))->hash($password),
            $roles,
        ]);

        return (string) $this->pdo->lastInsertId();
    }

    private function storedHash(string $id): string
    {
        $statement = $this->pdo->prepare('SELECT password FROM users WHERE id = ?');
        $statement->execute([$id]);

        return (string) $statement->fetchColumn();
    }

    /** @param iterable<string> $values */
    private function iterate(iterable $values): \Generator
    {
        yield from $values;
    }
}
