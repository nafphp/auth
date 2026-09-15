<?php

declare(strict_types=1);

namespace Naf\Auth\Support;

use SensitiveParameter;

/**
 * Password hashing for providers that verify passwords themselves.
 *
 * It exists for one reason: the unknown-user path is where hand-written login
 * code leaks. `verify()` hashes against a decoy when there is no account, so a
 * rejected login costs the same either way and the response time never tells an
 * attacker which usernames exist.
 */
final class PasswordHasher
{
    private ?string $decoy = null;

    /** @param array<string, mixed> $options Algorithm options, e.g. ['cost' => 12]. */
    public function __construct(
        private readonly string|int|null $algorithm = PASSWORD_DEFAULT,
        private readonly array $options = [],
    ) {
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, $this->algorithm, $this->options);
    }

    /**
     * Check a password against a stored hash.
     *
     * Pass null when the account does not exist or has no usable hash: the decoy
     * is verified instead and the answer is always false.
     */
    public function verify(#[SensitiveParameter] string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            password_verify($password, $this->decoy());

            return false;
        }

        return password_verify($password, $hash);
    }

    /** True when the stored hash predates the current algorithm or options. */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /** A real hash at the configured cost, so the unknown-user path is never the cheap one. */
    private function decoy(): string
    {
        return $this->decoy ??= $this->hash('');
    }
}
