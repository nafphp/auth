<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use NixPHP\ORM\Repository\AbstractRepository;

final class StrangerUserRepository extends AbstractRepository
{
    protected function getEntityClass(): string
    {
        return StrangerUser::class;
    }
}
