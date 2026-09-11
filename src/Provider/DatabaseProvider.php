<?php

declare(strict_types=1);

namespace NixPHP\Auth\Provider;

use Closure;
use InvalidArgumentException;
use LogicException;
use NixPHP\Auth\Identity\IdentityInterface;
use NixPHP\Auth\Support\PasswordHasher;
use PDO;
use PDOStatement;
use RuntimeException;

/** Password authentication with an application-owned PDO connection, without an ORM. */
final class DatabaseProvider extends PasswordProvider
{
    /** Hash from the lookup immediately preceding PasswordProvider verification. */
    private ?string $loadedHash = null;
    private readonly string $table;
    private readonly string $usernameColumn;
    private readonly string $passwordColumn;
    private readonly string $identifierColumn;

    /**
     * @param Closure(array<string, mixed>): ?IdentityInterface $identityFactory Maps a row without its password hash; null rejects the account.
     */
    public function __construct(
        private readonly PDO $connection,
        PasswordHasher $hasher,
        private readonly Closure $identityFactory,
        string $table = 'users',
        string $usernameField = 'username',
        private readonly string $passwordField = 'password',
        private readonly string $identifierField = 'id',
    ) {
        parent::__construct($hasher);
        $this->table = $this->quote($table);
        $this->usernameColumn = $this->quote($usernameField);
        $this->passwordColumn = $this->quote($passwordField);
        $this->identifierColumn = $this->quote($identifierField);
    }

    public function find(string $identifier): ?IdentityInterface
    {
        return $identifier === '' ? null : $this->lookup($this->identifierColumn, $identifier);
    }

    protected function findByUsername(string $username): ?IdentityInterface
    {
        return $username === '' ? null : $this->lookup($this->usernameColumn, $username);
    }

    protected function passwordHash(IdentityInterface $identity): ?string
    {
        return $this->loadedHash;
    }

    protected function storePasswordHash(IdentityInterface $identity, string $hash): void
    {
        // Do not overwrite a password reset that happened after the lookup.
        $this->execute(
            "UPDATE {$this->table} SET {$this->passwordColumn} = :new_hash"
            . " WHERE {$this->identifierColumn} = :identifier AND {$this->passwordColumn} = :old_hash",
            ['new_hash' => $hash, 'identifier' => $identity->getIdentifier(), 'old_hash' => $this->loadedHash],
        );
    }

    private function lookup(string $column, string $value): ?IdentityInterface
    {
        $this->loadedHash = null;
        $statement = $this->execute("SELECT * FROM {$this->table} WHERE {$column} = :value", ['value' => $value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        // A source must identify one account, even if its schema lacks a unique constraint.
        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new LogicException('Authentication lookup matched multiple accounts. Use unique identifiers and usernames.');
        }

        $identifier = $row[$this->identifierField] ?? null;
        if ((!is_string($identifier) && !is_int($identifier)) || (string) $identifier === '') {
            throw new LogicException('The account must have a non-empty string or integer identifier.');
        }
        $identifier = (string) $identifier;
        $hash = $row[$this->passwordField] ?? null;
        unset($row[$this->passwordField]);

        $identity = ($this->identityFactory)($row);
        if ($identity === null) {
            return null;
        }
        if (!$identity instanceof IdentityInterface || $identity->getIdentifier() !== $identifier) {
            throw new LogicException('The identity factory must return an IdentityInterface with the account identifier, or null.');
        }

        $this->loadedHash = is_string($hash) && $hash !== '' ? $hash : null;
        return $identity;
    }

    /** @param array<string, string|null> $parameters */
    private function execute(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        if ($statement === false || !$statement->execute($parameters)) {
            throw new RuntimeException('Authentication database operation failed.');
        }
        return $statement;
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Use a simple table or column name: ' . $identifier);
        }
        $quote = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' : '"';
        return $quote . $identifier . $quote;
    }
}
