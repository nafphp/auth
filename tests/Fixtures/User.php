<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use NixPHP\Auth\Identity\IdentityInterface;
use NixPHP\ORM\Model\AbstractModel;

/** Exactly what the README asks of an ORM model: one interface, three methods. */
class User extends AbstractModel implements IdentityInterface
{
    protected string $username = '';
    protected string $password = '';
    protected string $roles = '';

    public function getIdentifier(): string { return (string) $this->id; }

    public function getRoles(): iterable
    {
        return $this->roles === '' ? [] : explode(',', $this->roles);
    }

    public function getPermissions(): iterable { return []; }

    public function getUsername(): string { return $this->username; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }
}
