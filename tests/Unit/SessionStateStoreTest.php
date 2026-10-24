<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Auth\Auth;
use Naf\Auth\Credentials\PasswordCredentials;
use Naf\Auth\Identity\Identity;
use Naf\Auth\Session\SessionStateStore;
use Naf\Form\Support\Csrf;
use Naf\Session\Core\Session;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Tests\Fixtures\ProviderSpy;

use function Naf\app;

/** What actually happens to a real PHP session when somebody signs in or out. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionStateStoreTest extends TestCase
{
    private Session $session;
    private SessionStateStore $store;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/.test-sessions';

        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }

        session_save_path($path);
        $this->session = new Session();
        $this->session->start();
        app()->container()->set(Session::class, $this->session);
        $this->store = new SessionStateStore($this->session);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testALoginRotatesTheSessionIdAndStoresTwoValues(): void
    {
        $this->session->regenerate(0);
        $before = session_id();

        $auth = new Auth($this->store);
        $auth->addProvider('ldap', new ProviderSpy(new Identity('42', ['admin'], ['secret.permission'])));

        self::assertTrue($auth->authenticate(new PasswordCredentials('alice', 'valid')));
        self::assertNotSame($before, session_id(), 'A login must never keep the guest session ID.');
        self::assertSame(['provider' => 'ldap', 'identifier' => '42'], $_SESSION['auth']);
        self::assertStringNotContainsString('secret.permission', session_encode());
        self::assertStringNotContainsString('valid', session_encode());
    }

    public function testTheNextRequestReloadsTheAccountRatherThanTrustingTheSession(): void
    {
        $this->store->write('ldap', '42');
        $id = session_id();
        session_write_close();
        $_SESSION = [];
        session_id($id);
        $this->session->start();

        $ldap     = new ProviderSpy(new Identity('42', [], ['fresh.permission']));
        $database = new ProviderSpy(new Identity('42', [], ['wrong.permission']));

        $auth = new Auth(new SessionStateStore($this->session));
        $auth->addProvider('database', $database);
        $auth->addProvider('ldap', $ldap);

        self::assertTrue($auth->can('fresh.permission'));
        self::assertSame(1, $ldap->lookups);
        self::assertSame(0, $database->lookups, 'Identical identifiers must not cross sources.');
        self::assertSame($id, session_id(), 'Restoring is not a login and rotates nothing.');
    }

    public function testLogoutRotatesAndLeavesTheRestOfTheSessionAlone(): void
    {
        $this->store->write('ldap', '42');
        $this->session->set('cart', ['item']);
        $id = session_id();

        (new Auth($this->store))->logout();

        self::assertNull($this->store->read());
        self::assertNotSame($id, session_id());
        self::assertSame(['item'], $this->session->get('cart'));
    }

    public function testAMalformedRecordIsThrownAway(): void
    {
        $records = [
            'bad',
            ['provider' => 'ldap'],
            ['provider' => [], 'identifier' => '42'],
            ['provider' => 'ldap', 'identifier' => new stdClass()],
            ['provider' => '', 'identifier' => '42'],
            ['provider' => 'ldap', 'identifier' => ''],
        ];

        foreach ($records as $record) {
            $this->session->set('auth', $record);

            self::assertNull($this->store->read());
            self::assertNull($this->session->get('auth'));
        }
    }

    public function testLoginAdoptingAnIdentityAndLogoutInvalidatePreviouslyIssuedCsrfTokens(): void
    {
        $csrf = new Csrf();
        $auth = new Auth($this->store);
        $auth->addProvider('ldap', new ProviderSpy(new Identity('42')));
        $this->session->set('cart', ['item']);

        $guestToken = $csrf->token();
        self::assertTrue($auth->authenticate(new PasswordCredentials('alice', 'valid')));
        self::assertFalse($csrf->validate($guestToken));
        $loginToken = $csrf->token();
        self::assertNotSame($guestToken, $loginToken);
        self::assertTrue($csrf->validate($loginToken));

        $auth->setIdentity(new Identity('43'), 'ldap');
        self::assertFalse($csrf->validate($loginToken));
        $identityToken = $csrf->token();
        self::assertTrue($csrf->validate($identityToken));

        $auth->logout();
        self::assertFalse($csrf->validate($identityToken));
        self::assertTrue($csrf->validate($csrf->token()));
        self::assertSame(['item'], $this->session->get('cart'));
    }

    public function testConfiguredTrustSecretsAreClearedAtBothBoundaries(): void
    {
        $store = new SessionStateStore($this->session, resetKeys: ['_csrf', 'step_up']);
        $this->session->set('step_up', 'guest-secret');
        $store->write('ldap', '42');
        self::assertNull($this->session->get('step_up'));

        $this->session->set('step_up', 'authenticated-secret');
        $store->clear();
        self::assertNull($this->session->get('step_up'));
    }

    public function testASessionIdThatRefusesToRotateAbortsTheLogin(): void
    {
        $this->session->set('auth', ['provider' => 'old', 'identifier' => 'old-user']);
        $stubborn = new class extends Session {
            public function regenerate(int $intervalSeconds = 300): void
            {
            }
        };

        $auth = new Auth(new SessionStateStore($stubborn));
        $auth->addProvider('ldap', new ProviderSpy(new Identity('42')));

        try {
            $auth->authenticate(new PasswordCredentials('alice', 'valid'));
            self::fail('A session ID that will not rotate must abort the login.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('rotate', $exception->getMessage());
        }

        self::assertFalse($auth->check());
        self::assertNull($stubborn->get('auth'), 'Neither the new nor the old login may survive.');
    }
}
