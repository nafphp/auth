<?php

declare(strict_types=1);

namespace Naf\Auth\Identity;

/**
 * The signed-in person, as your application models them.
 *
 * This is the only contract your user class has to satisfy. `auth()->user()`
 * hands the very same object back, so your own properties and methods stay
 * reachable. Return an empty array from the two grant methods when you only
 * need logins and no permission checks.
 */
interface IdentityInterface
{
    /** Stable, non-empty key for this account. Written to the session on login. */
    public function getIdentifier(): string;

    /** @return iterable<string|\BackedEnum> */
    public function getRoles(): iterable;

    /** @return iterable<string|\BackedEnum> Everything this account may do, however you derive it. */
    public function getPermissions(): iterable;
}
