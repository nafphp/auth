<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Auth\Identity\UserInterface;
use Naf\Auth\Identity\UserProfile;

/** A user model as an application would write one: data, and nothing about doors. */
final class Member implements UserInterface
{
    public function __construct(
        private readonly string $id,
        public bool $active = true,
        private readonly ?string $name = 'Alice',
        private readonly ?string $email = 'alice@example.test',
        private readonly bool $emailVerified = true,
    ) {}

    public function getIdentifier(): string { return $this->id; }
    public function getRoles(): iterable { return []; }
    public function getPermissions(): iterable { return []; }
    public function isActive(): bool { return $this->active; }

    public function getProfile(): UserProfile
    {
        return new UserProfile($this->name, $this->email, $this->emailVerified);
    }
}
