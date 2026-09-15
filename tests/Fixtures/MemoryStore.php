<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Auth\Session\StateStoreInterface;

final class MemoryStore implements StateStoreInterface
{
    /** @var array{provider:string,identifier:string}|null */
    public ?array $record = null;
    public int $writes    = 0;
    public int $clears    = 0;

    public function read(): ?array
    {
        return $this->record;
    }

    public function write(string $provider, string $identifier): void
    {
        $this->writes++;
        $this->record = ['provider' => $provider, 'identifier' => $identifier];
    }

    public function clear(): void
    {
        $this->clears++;
        $this->record = null;
    }
}
