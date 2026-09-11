<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use NixPHP\Auth\Identity\IdentityInterface;

/** Counts how often the grants are asked for, and hands them back as a generator. */
final class CountingIdentity implements IdentityInterface
{
    public int $permissionReads = 0;
    public int $roleReads = 0;

    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public function __construct(
        private readonly string $identifier = '42',
        private readonly array $permissions = [],
        private readonly array $roles = [],
    ) {}

    public function getIdentifier(): string { return $this->identifier; }

    public function getPermissions(): iterable
    {
        $this->permissionReads++;
        yield from $this->permissions;
    }

    public function getRoles(): iterable
    {
        $this->roleReads++;
        yield from $this->roles;
    }
}
