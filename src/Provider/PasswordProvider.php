<?php

declare(strict_types=1);

namespace Naf\Auth\Provider;

use Naf\Auth\Credentials\{CredentialsInterface, PasswordCredentials};
use Naf\Auth\Identity\IdentityInterface;
use Naf\Auth\Support\PasswordHasher;

/**
 * Base class for the usual case: usernames and hashed passwords you store yourself.
 *
 * Say where to look up an account and where its hash sits; verification,
 * timing-safe rejection of unknown users and transparent rehashing are handled
 * here. Implement ProviderInterface directly for tokens, OIDC or LDAP binds.
 */
abstract class PasswordProvider implements ProviderInterface
{
    public function __construct(protected readonly PasswordHasher $hasher) {}

    /** Look up whoever the person claims to be. Null when there is no such account. */
    abstract protected function findByUsername(string $username): ?IdentityInterface;

    /** The stored hash, or null when the account has none: invited, disabled, SSO-only. */
    abstract protected function passwordHash(IdentityInterface $identity): ?string;

    abstract public function find(string $identifier): ?IdentityInterface;

    /** Persist an upgraded hash. Override to keep hashes current as the cost grows. */
    protected function storePasswordHash(IdentityInterface $identity, string $hash): void
    {
    }

    final public function authenticate(#[\SensitiveParameter] CredentialsInterface $credentials): ?IdentityInterface
    {
        if (!$credentials instanceof PasswordCredentials) {
            return null;
        }

        $identity = $this->findByUsername($credentials->username);
        $hash     = $identity === null ? null : $this->passwordHash($identity);

        // Verified even when there is no account, so a wrong username and a wrong
        // password cost the same and neither reveals which one was wrong.
        if (!$this->hasher->verify($credentials->password, $hash) || $identity === null || $hash === null) {
            return null;
        }

        if ($this->hasher->needsRehash($hash)) {
            $this->storePasswordHash($identity, $this->hasher->hash($credentials->password));
        }

        return $identity;
    }
}
