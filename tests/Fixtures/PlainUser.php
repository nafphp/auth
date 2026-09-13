<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Auth\Identity\IdentityInterface;
use Naf\ORM\Model\AbstractModel;

/** No getters at all: the provider has to fall back to the ORM field map. */
class PlainUser extends AbstractModel implements IdentityInterface
{
    public string $table = 'users';

    protected string $username = '';
    protected string $password = '';
    protected string $roles = '';

    public function getIdentifier(): string { return (string) $this->id; }
    public function getRoles(): iterable { return []; }
    public function getPermissions(): iterable { return []; }
}
