<?php

declare(strict_types=1);

namespace Naf\Auth\Credentials;

/**
 * Whatever a provider needs to prove who somebody is.
 *
 * Deliberately empty: a password, a one-time token and an OIDC code have
 * nothing in common but the moment they are handed to a provider.
 */
interface CredentialsInterface
{
}
