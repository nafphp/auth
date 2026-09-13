<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use NixPHP\Auth\Auth;
use NixPHP\Auth\Credentials\PasswordCredentials;
use NixPHP\Auth\Identity\Identity;
use NixPHP\Auth\Identity\UserProfile;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\{Member, MemoryStore, ProviderSpy};

/** One contract for every way of signing in, and what it is allowed to say. */
final class UserContractTest extends TestCase
{
    private MemoryStore $store;
    private Auth $auth;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->auth  = new Auth($this->store);
    }

    // ------------------------------------------------------------- Profiles

    public function testAProfileCarriesOnlyWhatWasPutInIt(): void
    {
        $profile = new UserProfile('Alice', 'alice@example.test', emailVerified: true);

        self::assertSame(
            ['name' => 'Alice', 'email' => 'alice@example.test', 'email_verified' => true],
            $profile->claims(),
        );
    }

    public function testWhatIsNotKnownIsAbsentRatherThanEmpty(): void
    {
        // A relying party can tell "no name" from "an empty name", and should be
        // allowed to.
        self::assertSame([], (new UserProfile())->claims());
        self::assertSame(['name' => 'Alice'], (new UserProfile('Alice'))->claims());
    }

    public function testAnUnconfirmedAddressSaysSo(): void
    {
        $claims = (new UserProfile(null, 'alice@example.test'))->claims();

        self::assertFalse($claims['email_verified'], 'having an address is not having confirmed it');
    }

    // ------------------------------------------------- Suspension, everywhere

    public function testASuspendedAccountCannotSignInWithAPassword(): void
    {
        $suspended = new Member('42', active: false);
        $this->auth->addProvider('users', new ProviderSpy($suspended));

        self::assertFalse($this->auth->authenticate(new PasswordCredentials('alice', 'valid')));
        self::assertFalse($this->auth->check());
    }

    public function testASuspendedAccountCannotBeLoaded(): void
    {
        $this->auth->addProvider('users', new ProviderSpy(new Member('42', active: false)));

        self::assertNull($this->auth->load('users', '42'));
    }

    public function testASuspendedAccountIsSignedOutOnItsNextRequest(): void
    {
        $member = new Member('42');
        $this->auth->addProvider('users', new ProviderSpy($member));
        $this->auth->authenticate(new PasswordCredentials('alice', 'valid'));

        self::assertTrue($this->auth->check(), 'precondition');

        // Suspended between two requests.
        $member->active = false;
        $this->auth->reset();

        self::assertFalse($this->auth->check());
        self::assertNull($this->store->record, 'and the record goes with it');
    }

    public function testAdoptingASuspendedAccountIsRefusedRatherThanIgnored(): void
    {
        // "Verified some other way" is not a reason to sign in somebody who may
        // not sign in — and whoever trusted the identity should hear about it.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/suspended/');

        $this->auth->setIdentity(new Member('42', active: false));
    }

    public function testAModelWithoutTheQuestionIsUnaffected(): void
    {
        // Plain IdentityInterface models predate isActive() and keep working
        // exactly as they did.
        $this->auth->addProvider('users', new ProviderSpy(new Identity('42')));

        self::assertTrue($this->auth->authenticate(new PasswordCredentials('alice', 'valid')));
        self::assertNotNull($this->auth->load('users', '42'));
    }
}
