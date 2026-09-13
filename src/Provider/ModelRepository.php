<?php

declare(strict_types=1);

namespace NixPHP\Auth\Provider;

use NixPHP\ORM\Core\EntityInterface;
use NixPHP\ORM\Core\EntityManager;
use NixPHP\ORM\Repository\AbstractRepository;
use PDO;

/**
 * A repository for one model, built from the model class alone.
 *
 * It exists so that signing people in does not start with writing an empty
 * subclass whose only content is the name of a class you already named in the
 * configuration. Applications that have a repository of their own keep using it;
 * this is what happens when they do not.
 */
final class ModelRepository extends AbstractRepository
{
    /** @param class-string<EntityInterface> $model */
    public function __construct(
        PDO $pdo,
        EntityManager $entityManager,
        private readonly string $model,
    ) {
        parent::__construct($pdo, $entityManager);
    }

    protected function getEntityClass(): string
    {
        return $this->model;
    }
}
