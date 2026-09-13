# Working on naf/auth

NAF is a small PHP framework with optional Composer plugins. Its core owns boot,
configuration, the service container, routing, events and PSR-7 responses. Prefer existing
NAF helpers, services and extension interfaces; keep application business rules in the host.
This package declares `type: naf-plugin` and is discovered after installation in a NAF host.
The plugin repository itself is not the application's web root.

Before changing code, read the [shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md)
and [release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the same documents are in the sibling `docs/` checkout;
use the linked copies when working from a standalone clone. Preserve other contributors' work.
Review and update user documentation with every behavior change. Source fixes use an RC branch;
verified documentation-only changes can be merged and published by the agent.

## What this plugin does

`naf/auth` manages identities, authentication providers, session restoration and permissions.
It accepts your own user model. OAuth protocols belong in `naf/oauth-client` or `naf/oauth-server`.
Install with `composer require naf/auth` in the host. Configure providers in `auth:providers`
or the documented ORM user-model setup; browser persistence additionally needs `naf/session`
or an explicit `StateStoreInterface` binding.

## Use it

Import `Naf\Auth\auth`. After configuring a provider, protect a handler before reading data:

```php
<?php
use function Naf\Auth\auth;
use function Naf\json;

// Inside a routed controller action:
auth()->requireLogin();
auth()->requirePermission('reports.read');
return json(['userId' => auth()->id()]);
```

Use `authenticate(CredentialsInterface)` for login, `logout()` for logout and the policy API
for resource checks. `setIdentity()` adopts an already verified identity; it does not check
credentials. A provider name is required when several providers are registered. Authentication
without a persistent store lasts only for the request.

## Change it here

Start at [bootstrap.php](bootstrap.php), [Auth](src/Auth.php), [providers](src/Provider/),
[identities](src/Identity/), [state stores](src/Session/) and [PasswordHasher](src/Support/PasswordHasher.php).
Use these contracts instead of introducing a second login or permission implementation.
Preserve suspended-account rejection, provider/identifier matching on restoration and session
rotation/clearing. Reset request identity appropriately in long-running workers. Required
class-name provider registrations must be resolvable by the bootstrap's factory.

## Verify

Run `composer test`, `composer analyse` and `composer validate --strict`. Test successful and
failed login, guest denial, permissions, logout, persistence and suspended accounts as relevant.
Use [tests](tests/) and the existing state-store fixtures. A browser-flow change needs HTTP
checks across requests, not only direct calls to `Auth`.

User docs: [Authentication](https://nafphp.github.io/docs/auth/),
[login recipe](https://nafphp.github.io/docs/recipes/login-form/).
