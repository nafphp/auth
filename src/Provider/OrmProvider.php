<?php

declare(strict_types=1);

namespace Naf\Auth\Provider;

use LogicException;
use Naf\Auth\Identity\IdentityInterface;
use Naf\Auth\Support\PasswordHasher;
use Naf\ORM\Core\EntityInterface;
use Naf\ORM\Core\EntityManager;
use Naf\ORM\Repository\AbstractRepository;

/**
 * Accounts stored with naf/orm.
 *
 * Point it at the repository that returns your user model and it covers both
 * provider questions: login looks the account up by its username column,
 * later requests reload it by its primary key. The model itself has to
 * implement IdentityInterface — that is what keeps `auth()->user()` returning
 * your own class with your own getters.
 *
 * Requires naf/orm. Everything else about your schema stays yours: name the
 * columns in the constructor.
 */
final class OrmProvider extends PasswordProvider
{
    /**
     * @param AbstractRepository $repository The repository created by the bootstrap.
     * @param string $usernameField  Column people type into the login form.
     * @param string $passwordField  Column holding the password hash.
     * @param string $identifierField Column matching IdentityInterface::getIdentifier(), normally the primary key.
     */
    public function __construct(
        private readonly AbstractRepository $repository,
        PasswordHasher $hasher,
        private readonly EntityManager $entityManager,
        private readonly string $usernameField = 'username',
        private readonly string $passwordField = 'password',
        private readonly string $identifierField = 'id',
    ) {
        parent::__construct($hasher);
    }

    public function find(string $identifier): ?IdentityInterface
    {
        return $identifier === '' ? null : $this->lookup($this->identifierField, $identifier);
    }

    protected function findByUsername(string $username): ?IdentityInterface
    {
        return $username === '' ? null : $this->lookup($this->usernameField, $username);
    }

    protected function passwordHash(IdentityInterface $identity): ?string
    {
        $hash = $identity instanceof EntityInterface ? $this->read($identity, 'get') : null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Writes the upgraded hash back when the model has a setter for the password
     * column, so hashes follow the cost you configure. Models without one keep
     * their old hash.
     */
    protected function storePasswordHash(IdentityInterface $identity, string $hash): void
    {
        $setter = $this->accessor('set');

        if (!$identity instanceof EntityInterface || !method_exists($identity, $setter)) {
            return;
        }

        $identity->$setter($hash);
        $this->entityManager->save($identity);
    }

    private function lookup(string $field, string $value): ?IdentityInterface
    {
        $entity = $this->repository->findOneBy($field, $value);

        if ($entity === null) {
            return null;
        }

        if (!$entity instanceof IdentityInterface) {
            throw new LogicException($entity::class . ' must implement ' . IdentityInterface::class . ' to be used for authentication.');
        }

        return $entity;
    }

    /** The model's own getter wins; otherwise the column is read from the ORM field map. */
    private function read(EntityInterface $entity, string $prefix): mixed
    {
        $accessor = $this->accessor($prefix);

        return method_exists($entity, $accessor)
            ? $entity->$accessor()
            : $entity->getFields()[$this->passwordField] ?? null;
    }

    private function accessor(string $prefix): string
    {
        return $prefix . str_replace('_', '', ucwords($this->passwordField, '_'));
    }
}
