<?php

declare(strict_types=1);

namespace NixPHP\Auth\Credentials;

final readonly class PasswordCredentials implements CredentialsInterface
{
    public function __construct(
        public string $username,
        #[\SensitiveParameter] public string $password,
    ) {}

    /** Keeps the password out of var_dump(), stack traces and debug bars. */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => '[redacted]'];
    }
}
