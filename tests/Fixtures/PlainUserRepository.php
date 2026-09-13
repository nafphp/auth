<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\ORM\Repository\AbstractRepository;

final class PlainUserRepository extends AbstractRepository
{
    protected function getEntityClass(): string
    {
        return PlainUser::class;
    }
}
