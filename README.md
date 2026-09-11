<div align="center">

![NixPHP](https://nixphp.github.io/docs/assets/nixphp-logo-small-square.png)

[![NixPHP Auth Plugin](https://github.com/nixphp/auth/actions/workflows/php.yml/badge.svg)](https://github.com/nixphp/auth/actions/workflows/php.yml)

</div>

[← Back to NixPHP](https://github.com/nixphp/framework)

---

# nixphp/auth

> **Log people in, and check what they may do — with your own user model.**

```php
auth()->authenticate(new PasswordCredentials($username, $password));   // sign in
auth()->user();                                                   // your own User object
auth()->can('posts.edit');                                        // bool, guests included
auth()->requireRole('admin');                                     // or a 403 leaves the controller
```

> 🧩 Part of the official NixPHP plugin collection.
> Install it when you need logins, and nothing else.

---

## What this plugin is

It answers two questions and owns nothing else:

1. **Who is this?** — verify credentials, remember the person across requests.
2. **What may they do?** — permissions, roles, and rules that depend on the object at hand.

It brings **no user table, no ORM and no opinion about where your accounts live**. That is deliberate:
your accounts may be rows in a database, entries in a directory, or records behind an API. The piece
that knows which is called a **provider**, and this plugin ships one for `nixphp/orm` — see
[Quickstart](#quickstart). For anything else you write about twenty lines yourself.

### The whole picture

```
  login form
      │  PasswordCredentials(username, password)
      ▼
 auth()->authenticate()
      │
      ▼
  your provider ──────────────▶ your user model        ← you own both of these
      │                          (IdentityInterface)
      │ verified
      ▼
  session: ['provider' => 'database', 'identifier' => '42']   ← never more than this
      │
      ▼
  next request: provider->find('42') ──▶ your user model, freshly loaded
```

Four moving parts, and you own two of them:

| Part | Who writes it | What it is |
| --- | --- | --- |
| **User model** | you | Any class of yours that implements `IdentityInterface`. `auth()->user()` hands it straight back. |
| **Provider** | you, `OrmProvider`, or `DatabaseProvider` | Knows where accounts live: verify credentials, reload an account by identifier. |
| **`auth()`** | this plugin | The one object you call. Signs people in and out and answers every permission question. |
| **Store** | this plugin | Writes those two values into the `nixphp/session` session. Nothing else is persisted. |

Because only the provider name and the identifier are stored, **every request reloads the account
through your provider**. A deleted, locked or demoted user is a guest again on their very next
click — permissions can never go stale.

---

## 📥 Installation

```bash
composer require nixphp/auth
```

Add `nixphp/session` so logins survive the next request:

```bash
composer require nixphp/session
```

Nothing to configure. PHP 8.3+ and NixPHP framework ^0.1.

---

## Quickstart

This is the whole setup with `nixphp/orm`. Without the ORM, swap step 3 for
[your own provider](#writing-your-own-provider) — everything else is identical.

### 1. A table

```sql
CREATE TABLE users (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    roles    VARCHAR(255) NOT NULL DEFAULT ''
);
```

Columns are yours to name — the provider is told which is which in step 3.

### 2. Your user model

One interface, three methods. Everything else on the class stays yours.

```php
use NixPHP\Auth\Identity\IdentityInterface;
use NixPHP\ORM\Model\AbstractModel;

class User extends AbstractModel implements IdentityInterface
{
    protected string $username = '';
    protected string $password = '';
    protected string $roles    = '';

    public function getIdentifier(): string { return (string) $this->id; }

    public function getRoles(): iterable
    {
        return $this->roles === '' ? [] : explode(',', $this->roles);
    }

    /** Return [] until you actually need permissions. */
    public function getPermissions(): iterable { return []; }

    public function getUsername(): string { return $this->username; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }
}
```

```php
use NixPHP\ORM\Repository\AbstractRepository;

class UserRepository extends AbstractRepository
{
    protected function getEntityClass(): string { return User::class; }
}
```

### 3. Configure the provider

The plugin's `bootstrap.php` registers its container factories, calls `addProvider()` for each
configured source and registers the configured policies. Declare the accounts source in your
application configuration:

```php
// app/config.php
use NixPHP\Auth\Provider\OrmProvider;

return ['auth' => [
    'providers' => ['database' => OrmProvider::class],
    'orm' => [
        'repository' => UserRepository::class,
        'username_field' => 'username', // e.g. email
        'password_field' => 'password', // e.g. password_hash
        'identifier_field' => 'id',
    ],
]];
```

**`'database'` is a name you chose**. It shows up in the session record and in
`auth()->providerName()`, and matters when you configure more than one source.
The ORM bootstrap supplies `RepositoryFactory` and `EntityManager`; the auth bootstrap injects
the resulting repository, entity manager and shared `PasswordHasher` into `OrmProvider`.

`OrmProvider` reads the hash through your model's own getter (`getPassword()` for a `password`
column) and falls back to the ORM's field map when there is none. When your model also has the
matching setter, an outdated hash is silently upgraded to the current cost on the next login.

### Without an ORM: plain PDO

Register your existing PDO connection in your application's bootstrap. No `nixphp/database`
or `nixphp/orm` is needed:

```php
// Application bootstrap: $pdo is your configured connection.
use function NixPHP\app;

app()->container()->set(PDO::class, $pdo);
```

Select the provider and, optionally, change its table and columns:

```php
// app/config.php
use NixPHP\Auth\Provider\DatabaseProvider;

return ['auth' => [
    'providers' => ['database' => DatabaseProvider::class],
    'database' => [
        'table' => 'accounts',
        'username_field' => 'email',
        'password_field' => 'password_hash',
        'identifier_field' => 'account_id',
    ],
]];
```

The defaults are `users`, `id`, `username` and `password` (a password hash).
Identifiers and usernames should be unique.

Table/column names must be simple identifiers, not SQL expressions or schema-qualified paths.
Values use prepared statements; names are quoted for MySQL or ANSI-style SQL (SQLite/PostgreSQL).
The provider leaves your connection configuration and transaction management unchanged.

The default factory in the bootstrap returns an `Identity` with the row's identifier and empty grants. To use your own
model or exclude disabled accounts, supply a mapper used for authentication **and** restoration:

```php
use NixPHP\Auth\Identity\IdentityInterface;

// Add this entry to auth.database in app/config.php.
'identity_factory' => static function (array $row): ?IdentityInterface {
    if (!$row['enabled']) {
        return null;
    }
    return new User($row); // Your class implementing IdentityInterface.
},
```

The mapper receives the row **without the password-hash column**. Its identity must retain the
row's identifier as a string; it can supply roles and permissions however your application stores
them. Returning `null` rejects an account. Ambiguous lookups raise an error instead of selecting
an arbitrary user.

The existing `PasswordProvider` handles verification and rehashing. The database provider writes
upgraded hashes back only if the stored hash has not changed since lookup, preserving concurrent
password resets. No schema or migration is installed. Integration tests use SQLite.

### 4. Log in

```php
use NixPHP\Auth\Credentials\PasswordCredentials;
use function NixPHP\Auth\auth;

if (!auth()->authenticate(new PasswordCredentials($username, $password))) {
    return render('login', ['error' => 'Invalid username or password.']);
}

return redirect('/dashboard');
```

`authenticate()` returns `false` for wrong credentials and never says which half was wrong. An unknown
username still costs a full password hash, so response times give nothing away either.

### 5. Use it anywhere

```php
auth()->check();                // bool
auth()->user();                 // your User, or null
auth()->user()?->getUsername(); // your own methods, right there
auth()->id();                   // '42', or null
auth()->can('posts.edit');      // bool — false for guests, no null check
auth()->logout();
```

That is the entire flow.

---

## Permissions and roles

Every check returns a plain `bool` and denies guests, so you never need a null check first.
Several names in one call mean **all of them**:

```php
auth()->can('posts.edit');
auth()->can('posts.edit', 'posts.publish');      // both
auth()->canAny('posts.edit', 'posts.publish');   // at least one
auth()->can(Permission::PostsEdit);              // your own backed enum works too

auth()->hasRole('admin');
auth()->hasRole('admin', 'editor');              // both
auth()->hasAnyRole('admin', 'editor');           // at least one
```

Matching is exact and case-sensitive. There is no wildcard, no admin bypass and no automatic
role-to-permission expansion: `getPermissions()` returns what the person may actually do, however
you choose to derive it.

Your grants are read **once** per check, so a model that queries a database or a directory inside
`getPermissions()` pays for one lookup, not one per permission.

---

## Requiring things (401 and 403)

In a controller, say what the route needs and let it throw:

```php
auth()->requireLogin();
auth()->requirePermission('posts.edit', 'posts.publish');   // all of them
auth()->requireRole('admin');
```

A guest raises `UnauthenticatedException` (401), a signed-in person without the grant raises
`ForbiddenException` (403). Left alone, NixPHP renders its own 401 and 403 pages. Catch them when
you want something else:

```php
use NixPHP\Auth\Exceptions\{ForbiddenException, UnauthenticatedException};

try {
    auth()->requirePermission('posts.edit');
} catch (UnauthenticatedException) {
    return redirect('/login');
} catch (ForbiddenException $e) {
    return json(['error' => $e->getMessage()], $e->getStatusCode());
}
```

Every requirement implies a signed-in user, so `requirePermission(...$configured)` with an empty
list asks for a login and nothing more. For "any of these", check and throw yourself:

```php
if (!auth()->canAny('posts.edit', 'posts.publish')) {
    throw new ForbiddenException();
}
```

---

## Per-object rules

When "may edit" depends on *which* object, register a rule for that class:

```php
// Add this to the auth configuration in app/config.php.
'policies' => [
    Post::class => static fn(IdentityInterface $user, string $action, Post $post): bool
        => $action === 'edit' && $post->authorId === $user->getIdentifier(),
],
```

The bootstrap registers these callbacks. Application logic only asks:

```php
auth()->allows('edit', $post);
```

The policy owns the whole decision: no policy means no, and a global permission never overrules it.
The exact class wins, then its nearest registered parent.

---

## Sessions

With `nixphp/session` installed, a successful login is remembered and the session ID is rotated —
a login that cannot rotate its ID is aborted rather than published. Only two values are stored:

```php
$_SESSION['auth'] = ['provider' => 'database', 'identifier' => '42'];
```

`auth()->logout()` clears the login and rotates the ID again, leaving the rest of the session
(carts, flashes, language) untouched.

Turn persistence off for a stateless API:

```php
// app/config.php
return ['auth' => ['session' => false]];
```

`true` demands the session plugin instead of quietly degrading to a login that is gone on the next
click; the default (`null`) persists as soon as `nixphp/session` is installed. Bind your own
`StateStoreInterface` to store the record somewhere else entirely.

---

## Several sources

Register as many as you like and name the one you mean:

```php
// auth.providers in app/config.php
'providers' => [
    'database' => OrmProvider::class,
    'ldap' => LdapProvider::class,
],
```

```php
// Application logic

auth()->authenticate($credentials, 'ldap');   // only LDAP is asked
auth()->providerName();                  // 'ldap'
```

With one provider the name is optional. With several, `authenticate()` without one throws rather than
guessing. A failed attempt never falls through to the next source, and a restored login always
comes from the source it was created with — even when two sources use the same identifiers.

A configured class name must have a container binding. Custom provider factories belong in the
application bootstrap, alongside their dependencies:

```php
$container = app()->container();
$container->set(LdapProvider::class, static fn() => new LdapProvider(
    $container->get(LdapClient::class),
));
```

Provider resolution is lazy and uses `get()`; no implicit construction or autowiring fallback.
Finish booting the plugins and registering application dependencies before calling `auth()`.
Custom bindings made before the auth bootstrap take precedence; factories can also be replaced
before their first resolution. Additional imperative `addProvider()` and `policy()` calls belong
in the application bootstrap after the auth plugin has booted.

When constructing providers directly (for example in tests), pass their dependencies explicitly:
`DatabaseProvider($pdo, $hasher, $identityFactory)` and
`OrmProvider($repository, $hasher, $entityManager)`. `SessionStateStore` requires a `Session`.
The former `register()` and `resolveStore()` helpers have been removed; bootstrap owns this work.

`PasswordHasher` is a shared container service. To change the hashing algorithm or cost, bind
it in the application bootstrap before resolving providers:

```php
$container->set(PasswordHasher::class, static fn() => new PasswordHasher(
    PASSWORD_BCRYPT, ['cost' => 12],
));
```

---

## Writing your own provider

A provider answers the same two questions for any backend. For usernames and hashes you store
yourself, extend `PasswordProvider` and the verification, the timing-safe rejection of unknown
accounts and the rehashing are already handled:

```php
use NixPHP\Auth\Identity\IdentityInterface;
use NixPHP\Auth\Provider\PasswordProvider;
use NixPHP\Auth\Support\PasswordHasher;

final class ApiUserProvider extends PasswordProvider
{
    public function __construct(private readonly UserApi $api, PasswordHasher $hasher)
    {
        parent::__construct($hasher);
    }

    protected function findByUsername(string $username): ?IdentityInterface
    {
        return $this->api->byEmail($username);
    }

    protected function passwordHash(IdentityInterface $identity): ?string
    {
        return $identity instanceof ApiUser ? $identity->passwordHash : null;
    }

    public function find(string $identifier): ?IdentityInterface
    {
        return $this->api->byId($identifier);
    }

    /** Optional: keep hashes current as the cost grows. */
    protected function storePasswordHash(IdentityInterface $identity, string $hash): void
    {
        $this->api->updateHash($identity->getIdentifier(), $hash);
    }
}
```

For tokens, OIDC or an LDAP bind there is no stored hash to compare, so implement
`ProviderInterface` directly — `authenticate()` and `find()`, nothing else.

Already verified the person some other way (registration, an invite link, a CLI command)? Set the already verified identity explicitly:

```php
auth()->setIdentity($user, 'database');   // named: persisted like a normal login
auth()->setIdentity($user);               // unnamed: this request only
```

`setIdentity()` trusts the supplied identity and does not verify credentials. With a provider
name it also updates the session when persistence is enabled. Without a name it clears any
previous persisted authentication and sets the identity for this request only.

---

## Reference

Everything the plugin exposes.

**`auth()`** — the shared manager, registered by `bootstrap.php` and resolved on first use.

| Method | Answers |
| --- | --- |
| `addProvider(string $name, ProviderInterface\|string $provider)` | Register a source of accounts. |
| `hasProvider(string $name)` | Is that name taken? |
| `authenticate(CredentialsInterface $credentials, ?string $provider = null)` | Verify and sign in. `bool` |
| `setIdentity(IdentityInterface $identity, ?string $provider = null)` | Adopt an already verified identity; optionally persist it. No credential verification. |
| `logout()` / `reset()` | End the login / forget the loaded model without logging out. |
| `check()` / `user()` / `id()` / `providerName()` | Is anybody signed in, and who. |
| `can(...$permissions)` / `canAny(...$permissions)` | All of them / at least one. `bool` |
| `hasRole(...$roles)` / `hasAnyRole(...$roles)` | All of them / at least one. `bool` |
| `policy(string $class, callable $rule)` / `allows($action, object $resource)` | Rules for one object. |
| `requireLogin()` / `requirePermission(...)` / `requireRole(...)` | The same checks, as 401/403. |

**Classes and contracts**

| Namespace | Name | Responsibility |
| --- | --- | --- |
| `Identity` | `IdentityInterface` | Your user: identifier, roles, permissions. |
| `Identity` | `Identity` | A ready-made identity for CLI tools and tests. |
| `Credentials` | `CredentialsInterface` | Marker for whatever a provider needs. |
| `Credentials` | `PasswordCredentials` | Username and password, redacted in debug output. |
| `Provider` | `ProviderInterface` | `authenticate()` and `find()`. |
| `Provider` | `PasswordProvider` | Base class for stored password hashes. |
| `Provider` | `DatabaseProvider` | Accounts accessed through an existing PDO connection. |
| `Provider` | `OrmProvider` | Accounts stored with `nixphp/orm`. |
| `Session` | `StateStoreInterface` | Read, write and clear the two persisted values. |
| `Session` | `SessionStateStore` | The `nixphp/session` implementation, with ID rotation. |
| `Support` | `PasswordHasher` | Hashing, rehash detection, decoy verification. |
| `Exceptions` | `UnauthenticatedException` | 401. |
| `Exceptions` | `ForbiddenException` | 403. |

See [architecture notes](docs/architecture.md) for the decisions behind this shape and the exact
restore and rotation semantics.

---

## Development

```bash
composer install
composer test
composer analyse
```

CI covers PHP 8.3, 8.4 and 8.5.

---

## License

MIT License.
