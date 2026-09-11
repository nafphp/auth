<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use NixPHP\Auth\Credentials\{CredentialsInterface, PasswordCredentials};
use NixPHP\Auth\Identity\IdentityInterface;
use NixPHP\Auth\Provider\ProviderInterface;

final class ProviderSpy implements ProviderInterface
{
    public int $attempts = 0;
    public int $lookups = 0;

    public function __construct(public ?IdentityInterface $identity = null) {}

    public function find(string $identifier): ?IdentityInterface
    {
        $this->lookups++;

        return $this->identity?->getIdentifier() === $identifier ? $this->identity : null;
    }

    public function authenticate(#[\SensitiveParameter] CredentialsInterface $credentials): ?IdentityInterface
    {
        $this->attempts++;

        return $credentials instanceof PasswordCredentials && $credentials->password === 'valid'
            ? $this->identity
            : null;
    }
}
