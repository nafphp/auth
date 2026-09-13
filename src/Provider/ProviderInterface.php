<?php

declare(strict_types=1);

namespace Naf\Auth\Provider;

use Naf\Auth\Credentials\CredentialsInterface;
use Naf\Auth\Identity\IdentityInterface;

/**
 * Where your accounts live.
 *
 * Two questions, nothing else: who owns these credentials, and who belongs to
 * this identifier. The first runs on login, the second on every later request
 * that restores the login from the session.
 */
interface ProviderInterface
{
    /** Null means "not these credentials" — never say which half was wrong. */
    public function authenticate(#[\SensitiveParameter] CredentialsInterface $credentials): ?IdentityInterface;

    /** Reload an account. Null once it may no longer sign in: deleted, locked, disabled. */
    public function find(string $identifier): ?IdentityInterface;
}
