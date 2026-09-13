<div align="center">

![NAF](assets/naf-logo-small-square.png)

[![NAF Auth Plugin](https://github.com/nafphp/auth/actions/workflows/php.yml/badge.svg)](https://github.com/nafphp/auth/actions/workflows/php.yml)

</div>

[← Back to NAF](https://github.com/nafphp/framework)

---

# naf/auth

> **Log people in, and check what they may do — with your own user model.**

```php
auth()->authenticate(new PasswordCredentials($username, $password));   // sign in
auth()->user();                                                   // your own User object
auth()->can('posts.edit');                                        // bool, guests included
auth()->requireRole('admin');                                     // or a 403 leaves the controller
```

> 🧩 Part of the official NAF plugin collection.
> Install it when you need logins, and nothing else.

---

## What this plugin is

It answers two questions and owns nothing else:

1. **Who is this?** — verify credentials, remember the person across requests.
2. **What may they do?** — permissions, roles, and rules that depend on the object at hand.

It brings **no user table, no ORM and no opinion about where your accounts live**. That is deliberate:
your accounts may be rows in a database, entries in a directory, or records behind an API. The piece
that knows which is called a **provider**, and this plugin ships one for `naf/orm` — see
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
| **Store** | this plugin | Writes those two values into the `naf/session` session. Nothing else is persisted. |

Because only the provider name and the identifier are stored, **every request reloads the account
through your provider**. A deleted, locked or demoted user is a guest again on their very next
click — permissions can never go stale.

---

## 📥 Installation

```bash
composer require naf/auth
```

Add `naf/session` so logins survive the next request:

```bash
composer require naf/session
```

Nothing to configure. PHP 8.3+ and NAF framework ^0.1.

---

## Quickstart

Two steps: a table, and a model. Nothing else is written by hand.

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

One interface, five methods. Everything else on the class stays yours.

```php
use Naf\Auth\Identity\{UserInterface, UserProfile};
use Naf\ORM\Model\AbstractModel;

class User extends AbstractModel implements UserInterface
{
    protected string $username = '';
    protected string $password = '';
    protected string $roles    = '';
    protected int    $suspended = 0;

    public function getIdentifier(): string { return (string) $this->id; }

    public function getRoles(): iterable
    {
        return $this->roles === '' ? [] : explode(',', $this->roles);
    }

    /** Return [] until you actually need permissions. */
    public function getPermissions(): iterable { return []; }

    public function isActive(): bool { return $this->suspended === 0; }

    public function getProfile(): UserProfile
    {
        return new UserProfile($this->name, $this->email, $this->email_verified === 1);
    }

    public function getUsername(): string { return $this->username; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }
}
```

**`isActive()` is asked every time the account is loaded**, so suspending somebody takes effect
on their next request rather than when their session happens to expire. It applies to every way
of signing in — password, external provider, API token — because the question is asked once, in
`auth()`, rather than in each of them.

**`getProfile()` is what may be shown**, chosen deliberately. It is not a getter over the model:
a profile crosses boundaries — a consent screen, an ID token, a UserInfo answer — and a field
that reflected the model would export the next column somebody adds. Identity stays separate:
the identifier, the roles and the permissions answer "who is this and what may they do", the
profile answers "what may be said about them".

### 3. Point at the model

```php
// app/config.php
return ['auth' => [
    'users' => ['model' => User::class],
]];
```

That is the whole configuration. No repository class, no factory, no provider name: the
repository is built from the model, the connection comes from the ORM, and the source is
registered under the name **`users`** — which is what ends up in the session record and in
account links.

Name the columns only if yours differ:

```php
'users' => [
    'model' => User::class,
    'username_field' => 'email',
    'password_field' => 'password_hash',
],
```

`OrmProvider` reads the hash through your model's own getter (`getPassword()` for a `password`
column) and falls back to the ORM's field map when there is none. When your model also has the
matching setter, an outdated hash is silently upgraded to the current cost on the next login.

### The older, explicit form

Naming sources yourself still works and still takes precedence — it is the only way to have
several:

```php
return ['auth' => [
    'providers' => ['database' => OrmProvider::class],
    'orm' => ['repository' => UserRepository::class],
]];
```

An application written against this keeps working unchanged, including the source name it chose.

### Without an ORM: plain PDO

Register your existing PDO connection in your application's bootstrap. No `naf/database`
or `naf/orm` is needed:

```php
// Application bootstrap: $pdo is your configured connection.
use function Naf\app;

app()->container()->set(PDO::class, $pdo);
```

Select the provider and, optionally, change its table and columns:

```php
// app/config.php
use Naf\Auth\Provider\DatabaseProvider;

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
use Naf\Auth\Identity\IdentityInterface;

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
use Naf\Auth\Credentials\PasswordCredentials;
use function Naf\Auth\auth;

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
`ForbiddenException` (403). Left alone, NAF renders its own 401 and 403 pages. Catch them when
you want something else:

```php
use Naf\Auth\Exceptions\{ForbiddenException, UnauthenticatedException};

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

With `naf/session` installed, a successful login is remembered and the session ID is rotated —
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
click; the default (`null`) persists as soon as `naf/session` is installed. Bind your own
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
use Naf\Auth\Identity\IdentityInterface;
use Naf\Auth\Provider\PasswordProvider;
use Naf\Auth\Support\PasswordHasher;

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

Need the model first — to look up who an external login belongs to, to impersonate somebody, or
in a CLI tool? `auth()->load('database', '42')` reads it through the registered source and hands
it back. It changes nothing: no login, no session, no store. It answers `null` for an unknown
source, an empty identifier, a vanished account, or a provider that hands back somebody else —
the same guarantee restoration relies on, because restoration now calls it.

---

## Reference

Everything the plugin exposes.

**`auth()`** — the shared manager, registered by `bootstrap.php` and resolved on first use.

| Method | Answers |
| --- | --- |
| `addProvider(string $name, ProviderInterface\|string $provider)` | Register a source of accounts. |
| `hasProvider(string $name)` | Is that name taken? |
| `providers()` | The names of every registered source, in order. |
| `load(string $provider, string $identifier)` | Reload an account through a source, without signing anybody in. Touches no session. |
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
| `Identity` | `UserInterface` | Your user model: identity, plus whether the account is open and what may be shown. |
| `Identity` | `UserProfile` | Display name, e-mail, and whether that address was actually confirmed. |
| `Identity` | `IdentityInterface` | The older contract: identifier, roles, permissions. Still accepted. |
| `Identity` | `Identity` | A ready-made identity for CLI tools and tests. |
| `Credentials` | `CredentialsInterface` | Marker for whatever a provider needs. |
| `Credentials` | `PasswordCredentials` | Username and password, redacted in debug output. |
| `Provider` | `ProviderInterface` | `authenticate()` and `find()`. |
| `Provider` | `PasswordProvider` | Base class for stored password hashes. |
| `Provider` | `DatabaseProvider` | Accounts accessed through an existing PDO connection. |
| `Provider` | `OrmProvider` | Accounts stored with `naf/orm`. |
| `Provider` | `ModelRepository` | A repository built from a model class, so you need not write an empty one. |
| `Session` | `StateStoreInterface` | Read, write and clear the two persisted values. |
| `Session` | `SessionStateStore` | The `naf/session` implementation, with ID rotation. |
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
