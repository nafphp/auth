# Architecture notes

Why the plugin has this shape. The [README](../README.md) is the complete user-facing picture;
this file is the reasoning behind it.

## One implementation per question

The permission question is answered in exactly one place, `Auth::holds()`. There is no authorizer
service, no actor wrapper and no interface that mirrors the manager. An earlier draft had all three,
which meant `can`, `canAny`, `canAll`, `hasRole`, `hasAnyRole` and `hasAllRoles` existed three times
over — plus fallback copies inside the wrapper, because the authorizer interface declared fewer
methods than the authorizer had. Every extra layer was a second place for the same rule to drift.

Consequences that are deliberate, not oversights:

- **`Auth` is final and holds its own state.** No shared state object, no cloning. `authenticate()` takes
  the source name as an argument instead of `auth('name')` returning a copy, so there is nothing to
  keep in sync between instances.
- **No `Actor`.** `auth()->user()` returns the provider's own object and `auth()` answers the
  authorization questions about it. One way to reach the person, one way to ask about them.
- **Grants live on `IdentityInterface`.** Three methods, always present, so nothing has to test
  whether an identity happens to implement a second, optional contract. Returning `[]` is the
  honest answer for an application that only needs logins.
- **Variadics replace the ANY/ALL pairs.** `can('a', 'b')` is "both"; `canAny('a', 'b')` is "either".
  Listing nothing is vacuously true for the ALL forms, so `requirePermission(...$configured)` with an
  empty configuration asks for a login and nothing more.
- **`allows($action, $resource)` is separate from `can()`.** A global grant and a per-object rule are
  different questions, so they are different methods; the resource never sneaks in as a second
  argument that silently changes what `can()` means.
- **Policies live in `Auth`.** They are an array lookup and a callback. A registry service for that
  would be a class whose whole job is `isset()`.

## The provider is the seam

`ProviderInterface` declares `authenticate()` and `find()`. Everything a backend needs — a PDO
connection, an LDAP handle, an HTTP client — is a constructor dependency of the provider, resolved
through the container when it is registered by class name.

- `PasswordProvider` exists because the unknown-account path is what hand-written login code gets
  wrong. It verifies against a decoy hash when there is no account, so a wrong username and a wrong
  password cost the same.
- `OrmProvider` is the ORM implementation. It maps a repository and three column names onto
  those two questions, and requires the model to implement `IdentityInterface` — that requirement is
  what keeps `auth()->user()` returning the application's own class instead of a wrapper.
- Providers registered by name are resolved lazily and cached, so registering five sources in
  `bootstrap.php` builds none of them until somebody logs in.

## Persistence

`StateStoreInterface` reads, writes and clears exactly two values: the source name and the
identifier. Never the model, never the grants.

- Restoration reloads through `find()` and compares the returned identifier against the stored one,
  so a provider that hands back somebody else cannot silently swap the person. A missing account,
  an unregistered source or a malformed record clears the record instead of falling back.
- `SessionStateStore` rotates the session ID on login and verifies that it actually changed. A
  rotation that does not happen aborts the login rather than publishing a person onto the guest's ID.
- `SessionStateStore` receives its session through constructor injection. The bootstrap creates
  the store lazily when the manager needs persistence. No service starts a session in its constructor.
- The manager factory prefers a custom `StateStoreInterface` binding, then honours `auth:session`:
  false disables persistence, null enables it when `nixphp/session` is installed, true requires it.

## Wiring

`bootstrap.php` is the composition root. It registers `Auth`, `PasswordHasher`, `SessionStateStore`,
`DatabaseProvider` and `OrmProvider` as lazy container factories. The manager factory visibly calls
`addProvider()` for `auth:providers` and `policy()` for `auth:policies`. No source is enabled by default.
Custom bindings take precedence. `auth()` only gets the manager; it never registers services.

The providers receive their dependencies explicitly. The ORM repository is resolved or created by
`RepositoryFactory` in the bootstrap; neither the ORM provider nor the session adapter accesses
the global container. Providers registered by class name use the resolver injected into `Auth`,
which calls container `get()`. There is no implicit `new $class()` or `make()` fallback.

The PDO identity factory is also supplied by the bootstrap. It creates a fresh identity per lookup;
identities and credentials are data, not container singletons. Exceptions are created where errors
occur. Service construction and registration belong in the bootstrap, including application-owned
provider factories and any imperative policy/provider registrations.

Boot the auth plugin before using `auth()`, and resolve it after dependency plugins have booted.
Lazy factories allow registration before the session, PDO or ORM services exist. Unlike the previous
helper, this deliberately does not hide a missing or incorrectly ordered bootstrap.

No framework API, no event system and no guard registry is introduced. The two exceptions expose
`getStatusCode()` so the framework error handler renders 401 and 403 instead of a generic 500; they
carry the same value as their exception code and stay usable outside HTTP.

## What the plugin guarantees

- A login rotates the session ID, or it does not happen.
- Restoration distinguishes sources even when identifiers collide, and re-reads grants every request.
- A resource policy that denies — or the absence of one — is never bypassed by a global permission.
- An unknown username costs a full hash. Credentials are marked `#[\SensitiveParameter]` and
  `PasswordCredentials` redacts its debug output. Nothing but a source name and an identifier is
  persisted.
- A failed attempt leaves an existing login intact. Call `logout()` first if reauthentication should
  end the current session.

## What it leaves to the application

Password policy, rate limiting, lockout, MFA and invitation flows are account-lifecycle decisions.
Business rules such as last-admin protection or delegation caps belong in policies, next to the
mutation they guard. The session adapter inherits the storage and cookie guarantees of
`nixphp/session`; there is no token revocation store and no distributed limiter here.

## Plain PDO accounts

`DatabaseProvider` extends the existing password provider and accepts a PDO connection directly.
It provides parameterized lookup by username/identifier and password-hash upgrades without ORM
or database-plugin dependencies. A row mapper optionally creates the application identity or
rejects disabled accounts; the password hash stays out of the mapped row. The provider retains the hash of the current lookup for the synchronous PasswordProvider
verification flow, avoiding a second query. The mapper runs before that hash is assigned, so
a nested lookup from the mapper cannot replace the outer authentication's hash. Rehash updates
compare the original hash so concurrent resets are not overwritten. Table/column names are validated and quoted; ambiguous accounts fail.
