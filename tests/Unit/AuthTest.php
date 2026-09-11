<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use LogicException;
use NixPHP\Auth\Auth;
use NixPHP\Auth\Credentials\PasswordCredentials;
use NixPHP\Auth\Identity\Identity;
use NixPHP\Auth\Provider\ProviderInterface;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\{MemoryStore, ProviderSpy};

/** Registering sources, signing in and out, and restoring a login from the store. */
final class AuthTest extends TestCase
{
    private MemoryStore $store;
    private Auth $auth;
    private ProviderSpy $database;
    private ProviderSpy $ldap;

    protected function setUp(): void
    {
        $this->store    = new MemoryStore();
        $this->database = new ProviderSpy(new Identity('42', ['admin']));
        $this->ldap     = new ProviderSpy(new Identity('42', ['guest']));
        $this->auth     = new Auth($this->store);
    }

    private function withBoth(): Auth
    {
        return $this->auth
            ->addProvider('database', $this->database)
            ->addProvider('ldap', $this->ldap);
    }

    public function testNobodyIsSignedInByDefault(): void
    {
        self::assertFalse($this->auth->check());
        self::assertNull($this->auth->user());
        self::assertNull($this->auth->id());
        self::assertNull($this->auth->providerName());
    }

    public function testOneSourceNeedsNoName(): void
    {
        $this->auth->addProvider('database', $this->database);

        self::assertTrue($this->auth->authenticate(new PasswordCredentials('alice', 'valid')));
        self::assertSame('42', $this->auth->id());
        self::assertSame('database', $this->auth->providerName());
        self::assertSame(['provider' => 'database', 'identifier' => '42'], $this->store->record);
    }

    public function testSeveralSourcesAskForTheOneYouMean(): void
    {
        $this->withBoth();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('database, ldap');
        $this->auth->authenticate(new PasswordCredentials('alice', 'valid'));
    }

    public function testAttemptWithoutAnySourceSaysSo(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('addProvider');
        $this->auth->authenticate(new PasswordCredentials('alice', 'valid'));
    }

    public function testOnlyTheNamedSourceIsAsked(): void
    {
        $this->withBoth();

        self::assertTrue($this->auth->authenticate(new PasswordCredentials('alice', 'valid'), 'ldap'));
        self::assertSame(1, $this->ldap->attempts);
        self::assertSame(0, $this->database->attempts);
        self::assertSame('ldap', $this->auth->providerName());
    }

    public function testWrongCredentialsNeverFallBackToAnotherSource(): void
    {
        $this->withBoth();

        self::assertFalse($this->auth->authenticate(new PasswordCredentials('alice', 'wrong'), 'ldap'));
        self::assertSame(0, $this->database->attempts);
        self::assertFalse($this->auth->check());
        self::assertSame(0, $this->store->writes);
    }

    public function testAFailedAttemptLeavesTheCurrentPersonSignedIn(): void
    {
        $this->auth->addProvider('database', $this->database);
        $this->auth->authenticate(new PasswordCredentials('alice', 'valid'));

        self::assertFalse($this->auth->authenticate(new PasswordCredentials('mallory', 'wrong')));
        self::assertSame('42', $this->auth->id());
    }

    public function testARestoredLoginComesFromTheRecordedSourceEvenWhenIdentifiersCollide(): void
    {
        $this->withBoth();
        $this->store->record = ['provider' => 'ldap', 'identifier' => '42'];

        self::assertTrue($this->auth->hasRole('guest'));
        self::assertSame(1, $this->ldap->lookups);
        self::assertSame(0, $this->database->lookups, 'Only the recorded source may be asked.');
    }

    public function testTheStoreIsReadOncePerRequest(): void
    {
        $this->auth->addProvider('database', $this->database);
        $this->store->record = ['provider' => 'database', 'identifier' => '42'];

        $this->auth->check();
        $this->auth->user();
        $this->auth->can('admin');

        self::assertSame(1, $this->database->lookups);
    }

    public function testADeletedAccountIsAGuestAgainAndLosesItsRecord(): void
    {
        $this->auth->addProvider('database', new ProviderSpy(null));
        $this->store->record = ['provider' => 'database', 'identifier' => '42'];

        self::assertFalse($this->auth->check());
        self::assertNull($this->store->record);
        self::assertSame(1, $this->store->clears);
    }

    public function testARecordPointingAtAnUnregisteredSourceIsDropped(): void
    {
        $this->auth->addProvider('database', $this->database);
        $this->store->record = ['provider' => 'gone', 'identifier' => '42'];

        self::assertFalse($this->auth->check());
        self::assertSame(0, $this->database->lookups, 'A missing source must not fall back to another.');
        self::assertNull($this->store->record);
    }

    public function testASourceHandingBackSomebodyElseIsRejected(): void
    {
        $swapping = new class implements ProviderInterface {
            public function find(string $identifier): ?\NixPHP\Auth\Identity\IdentityInterface
            {
                return new Identity('99');
            }
            public function authenticate(#[\SensitiveParameter] \NixPHP\Auth\Credentials\CredentialsInterface $credentials): ?\NixPHP\Auth\Identity\IdentityInterface
            {
                return null;
            }
        };
        $this->auth->addProvider('database', $swapping);
        $this->store->record = ['provider' => 'database', 'identifier' => '42'];

        self::assertFalse($this->auth->check());
        self::assertNull($this->store->record);
    }

    public function testLogoutClearsMemoryAndStore(): void
    {
        $this->auth->addProvider('database', $this->database);
        $this->auth->authenticate(new PasswordCredentials('alice', 'valid'));

        $this->auth->logout();

        self::assertFalse($this->auth->check());
        self::assertNull($this->auth->providerName());
        self::assertNull($this->store->record);
    }

    public function testResetReloadsWithoutLoggingAnybodyOut(): void
    {
        $provider = new ProviderSpy(new Identity('42', ['editor']));
        $this->auth->addProvider('database', $provider);
        $this->store->record = ['provider' => 'database', 'identifier' => '42'];
        self::assertTrue($this->auth->hasRole('editor'));

        $provider->identity = new Identity('42', ['admin']);
        $this->auth->reset();

        self::assertTrue($this->auth->hasRole('admin'));
        self::assertSame(2, $provider->lookups);
    }

    public function testAnUnnamedLoginStaysInThisRequestAndClearsAnOlderRecord(): void
    {
        $this->auth->addProvider('database', $this->database);
        $this->store->record = ['provider' => 'database', 'identifier' => '99'];

        $this->auth->setIdentity(new Identity('7'));

        self::assertSame('7', $this->auth->id());
        self::assertNull($this->auth->providerName());
        self::assertNull($this->store->record, 'A transient login must not leave the old one restorable.');
        self::assertSame(0, $this->store->writes);
    }

    public function testALoginNeedsNoSessionOrHttp(): void
    {
        (new Auth())->setIdentity(new Identity('cli'));

        $auth = new Auth();
        $auth->setIdentity(new Identity('cli', ['ops']));

        self::assertTrue($auth->hasRole('ops'));
    }

    public function testAnEmptyIdentifierCannotSignIn(): void
    {
        $identity = new class implements \NixPHP\Auth\Identity\IdentityInterface {
            public function getIdentifier(): string { return ''; }
            public function getRoles(): iterable { return []; }
            public function getPermissions(): iterable { return []; }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->auth->setIdentity($identity);
    }

    public function testUserReturnsTheProvidersOwnObject(): void
    {
        $identity = new Identity('42');
        $this->auth->addProvider('database', new ProviderSpy($identity));
        $this->auth->authenticate(new PasswordCredentials('alice', 'valid'));

        self::assertSame($identity, $this->auth->user());
    }

    public function testClassNamesAreBuiltOnceAndOnlyWhenUsed(): void
    {
        $built = 0;
        $spy   = new ProviderSpy(new Identity('42'));
        $auth  = new Auth($this->store, function (string $class) use (&$built, $spy): object {
            $built++;
            self::assertSame(ProviderSpy::class, $class);
            return $spy;
        });
        $auth->addProvider('database', ProviderSpy::class);

        self::assertSame(0, $built, 'Registration alone must not build anything.');
        $auth->authenticate(new PasswordCredentials('alice', 'valid'));
        $auth->authenticate(new PasswordCredentials('alice', 'valid'));

        self::assertSame(1, $built);
    }

    public function testRegistrationRejectsWhatCannotWork(): void
    {
        $this->auth->addProvider('database', $this->database);

        $cases = [
            'empty name'    => fn() => $this->auth->addProvider('  ', $this->database),
            'duplicate'     => fn() => $this->auth->addProvider('database', $this->ldap),
            'wrong class'   => fn() => $this->auth->addProvider('other', \stdClass::class),
            'unknown name'  => fn() => $this->auth->authenticate(new PasswordCredentials('a', 'valid'), 'nope'),
        ];

        foreach ($cases as $label => $case) {
            try {
                $case();
                self::fail('Expected a rejection for: ' . $label);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRegistrationCanBeCheckedBeforeAdding(): void
    {
        self::assertFalse($this->auth->hasProvider('database'));
        $this->auth->addProvider('database', $this->database);
        self::assertTrue($this->auth->hasProvider('database'));
    }

    public function testAProviderOutageIsNotABadPassword(): void
    {
        $broken = new class implements ProviderInterface {
            public function find(string $identifier): ?\NixPHP\Auth\Identity\IdentityInterface { return null; }
            public function authenticate(#[\SensitiveParameter] \NixPHP\Auth\Credentials\CredentialsInterface $credentials): ?\NixPHP\Auth\Identity\IdentityInterface
            {
                throw new \RuntimeException('LDAP is down.');
            }
        };
        $this->auth->addProvider('ldap', $broken);

        $this->expectException(\RuntimeException::class);
        $this->auth->authenticate(new PasswordCredentials('alice', 'valid'));
    }

    public function testCredentialsKeepThePasswordOutOfDebugOutput(): void
    {
        $credentials = new PasswordCredentials('alice', 'hunter2');

        self::assertSame(['username' => 'alice', 'password' => '[redacted]'], $credentials->__debugInfo());
        self::assertStringNotContainsString('hunter2', print_r($credentials, true));
    }
}
