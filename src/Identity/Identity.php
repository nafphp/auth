<?php

declare(strict_types=1);

namespace Naf\Auth\Identity;

use InvalidArgumentException;

/**
 * A ready-made identity for cases where you have no model of your own:
 * command line tools, tests, and providers that only ever return a name.
 */
final readonly class Identity implements IdentityInterface
{
    /**
     * @param list<string|\BackedEnum> $roles
     * @param list<string|\BackedEnum> $permissions
     */
    public function __construct(
        private string $identifier,
        private array $roles = [],
        private array $permissions = [],
    ) {
        if ($identifier === '') {
            throw new InvalidArgumentException('An identity identifier cannot be empty.');
        }
    }

    public function getIdentifier(): string { return $this->identifier; }
    public function getRoles(): iterable { return $this->roles; }
    public function getPermissions(): iterable { return $this->permissions; }
}
