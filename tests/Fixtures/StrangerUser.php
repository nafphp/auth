<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use NixPHP\ORM\Model\AbstractModel;

/** A model that never got an IdentityInterface: the provider must say so. */
class StrangerUser extends AbstractModel
{
    public string $table = 'users';

    protected string $username = '';
    protected string $password = '';
    protected string $roles = '';
}
