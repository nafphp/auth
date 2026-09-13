<?php

declare(strict_types=1);

namespace Naf\Auth\Session;

/**
 * How a login survives the next request.
 *
 * Only two values are ever stored: which source the person came from and their
 * identifier. Never the model, never permissions — those are reloaded, so a
 * disabled account is a guest again on its next request.
 */
interface StateStoreInterface
{
    /** @return array{provider:string,identifier:string}|null */
    public function read(): ?array;

    /** Rotate the session ID before saving; a login must never keep the guest's ID. */
    public function write(string $provider, string $identifier): void;

    public function clear(): void;
}
