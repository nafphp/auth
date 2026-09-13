<?php

declare(strict_types=1);

namespace NixPHP\Auth\Identity;

/**
 * Your user model, as every way of signing in sees it.
 *
 * One contract for all of them. A password login, an external provider, an API
 * token and a directory bind differ in how somebody proves who they are; they do
 * not differ in what a signed-in person is. Each answers this same contract, and
 * everything downstream — permissions, ID tokens, UserInfo — works off it without
 * knowing which door was used.
 *
 * Two additions to IdentityInterface, and nothing else:
 *
 * - `isActive()` is asked every time the account is loaded, so suspending
 *   somebody takes effect on their next request rather than when their session
 *   or token happens to expire.
 * - `getProfile()` returns the fields that may be shown, chosen deliberately —
 *   see UserProfile.
 *
 * What this contract deliberately does not include: anything about OAuth, LDAP,
 * sessions or databases. A user model that knows how it was authenticated is a
 * model that has to change when a second way of signing in is added.
 */
interface UserInterface extends IdentityInterface
{
    /** False for a suspended, locked or otherwise barred account. */
    public function isActive(): bool;

    public function getProfile(): UserProfile;
}
