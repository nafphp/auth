<?php

declare(strict_types=1);

namespace Tests\Unit;

use NixPHP\Auth\Credentials\{CredentialsInterface, PasswordCredentials};
use NixPHP\Auth\Identity\{Identity, IdentityInterface};
use NixPHP\Auth\Provider\PasswordProvider;
use NixPHP\Auth\Support\PasswordHasher;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Password verification, and the timing-safe treatment of accounts that do not exist. */
final class PasswordProviderTest extends TestCase
{
    public function testHashesAreSaltedAndVerifiable(): void
    {
        $hasher = new PasswordHasher();

        $first  = $hasher->hash('hunter2');
        $second = $hasher->hash('hunter2');

        self::assertNotSame($first, $second, 'Two hashes of one password must differ.');
        self::assertTrue($hasher->verify('hunter2', $first));
        self::assertFalse($hasher->verify('hunter3', $first));
    }

    public function testAMissingHashStillCostsARealOne(): void
    {
        $hasher = new PasswordHasher();

        self::assertNull(self::decoy($hasher));
        self::assertFalse($hasher->verify('hunter2', null));

        $built = self::decoy($hasher);
        self::assertIsString($built, 'A missing hash must not be the cheap path.');
        self::assertTrue(password_verify('', $built));

        $hasher->verify('hunter2', '');
        self::assertSame($built, self::decoy($hasher), 'The decoy is built once and reused.');
    }

    public function testRehashIsAskedForWhenTheCostChanges(): void
    {
        $cheap = (new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]))->hash('hunter2');

        self::assertFalse((new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]))->needsRehash($cheap));
        self::assertTrue((new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 6]))->needsRehash($cheap));
    }

    public function testTheRightPasswordSignsThePersonIn(): void
    {
        $provider = $this->provider(['alice' => 'hunter2']);

        $identity = $provider->authenticate(new PasswordCredentials('alice', 'hunter2'));

        self::assertInstanceOf(IdentityInterface::class, $identity);
        self::assertSame('alice', $identity->getIdentifier());
    }

    public function testAWrongPasswordAndAnUnknownNameBothCostAHash(): void
    {
        $provider = $this->provider(['alice' => 'hunter2']);

        self::assertNull($provider->authenticate(new PasswordCredentials('alice', 'wrong')));
        self::assertNull(self::decoy($provider->hasher()), 'A real account is verified against its own hash.');

        self::assertNull($provider->authenticate(new PasswordCredentials('mallory', 'hunter2')));
        self::assertIsString(self::decoy($provider->hasher()), 'An unknown name is verified against the decoy.');
    }

    public function testAnAccountWithoutAHashCannotSignIn(): void
    {
        $provider = $this->provider(['invited' => null]);

        self::assertNull($provider->authenticate(new PasswordCredentials('invited', '')));
        self::assertIsString(self::decoy($provider->hasher()));
    }

    public function testOtherKindsOfCredentialsAreNotThisProvidersBusiness(): void
    {
        $provider = $this->provider(['alice' => 'hunter2']);
        $token = new class implements CredentialsInterface {};

        self::assertNull($provider->authenticate($token));
    }

    public function testAnOutdatedHashIsUpgradedOnTheNextLogin(): void
    {
        $provider = $this->provider(['alice' => 'hunter2'], storedCost: 4, currentCost: 6);

        self::assertNotNull($provider->authenticate(new PasswordCredentials('alice', 'hunter2')));
        self::assertIsString($provider->stored);
        self::assertTrue(password_verify('hunter2', $provider->stored));
        self::assertFalse((new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 6]))->needsRehash($provider->stored));
    }

    public function testACurrentHashIsLeftAlone(): void
    {
        $provider = $this->provider(['alice' => 'hunter2']);

        self::assertNotNull($provider->authenticate(new PasswordCredentials('alice', 'hunter2')));
        self::assertNull($provider->stored);
    }

    private static function decoy(PasswordHasher $hasher): ?string
    {
        return (new ReflectionProperty(PasswordHasher::class, 'decoy'))->getValue($hasher);
    }

    /** @param array<string, string|null> $accounts username => plain password, null for an account with no hash */
    private function provider(array $accounts, int $storedCost = 4, int $currentCost = 4): object
    {
        $seed   = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => $storedCost]);
        $hashes = [];

        foreach ($accounts as $username => $password) {
            $hashes[$username] = $password === null ? null : $seed->hash($password);
        }

        return new class($hashes, new PasswordHasher(PASSWORD_BCRYPT, ['cost' => $currentCost])) extends PasswordProvider {
            public ?string $stored = null;

            /** @param array<string, string|null> $hashes */
            public function __construct(private readonly array $hashes, PasswordHasher $hasher)
            {
                parent::__construct($hasher);
            }

            public function hasher(): PasswordHasher
            {
                return $this->hasher;
            }

            protected function findByUsername(string $username): ?IdentityInterface
            {
                return $this->find($username);
            }

            protected function passwordHash(IdentityInterface $identity): ?string
            {
                return $this->hashes[$identity->getIdentifier()] ?? null;
            }

            public function find(string $identifier): ?IdentityInterface
            {
                return array_key_exists($identifier, $this->hashes) ? new Identity($identifier) : null;
            }

            protected function storePasswordHash(IdentityInterface $identity, string $hash): void
            {
                $this->stored = $hash;
            }
        };
    }
}
