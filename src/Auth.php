<?php

declare(strict_types=1);

namespace Naf\Auth;

use BackedEnum;
use Closure;
use InvalidArgumentException;
use LogicException;
use Naf\Auth\Credentials\CredentialsInterface;
use Naf\Auth\Exceptions\ForbiddenException;
use Naf\Auth\Exceptions\UnauthenticatedException;
use Naf\Auth\Identity\IdentityInterface;
use Naf\Auth\Identity\UserInterface;
use Naf\Auth\Provider\ProviderInterface;
use Naf\Auth\Session\StateStoreInterface;
use SensitiveParameter;
use UnexpectedValueException;

/**
 * The whole plugin, in one object: who is signed in, and what they may do.
 *
 * Reach it through `auth()`. It answers every check with a plain bool and
 * denies guests, so application code never needs a null check first.
 */
final class Auth
{
    /** @var array<string, ProviderInterface|class-string<ProviderInterface>> */
    private array $providers = [];

    /** @var array<class-string, callable(IdentityInterface, string, object): bool> */
    private array $policies = [];

    private ?IdentityInterface $identity = null;
    private ?string $providerName        = null;
    private bool $restored               = false;

    /**
     * @param StateStoreInterface|null $store Null keeps the login to this request only.
     * @param (Closure(class-string<ProviderInterface>): object)|null $factory Resolves providers registered by class name.
     */
    public function __construct(
        private readonly ?StateStoreInterface $store = null,
        private readonly ?Closure $factory = null,
    ) {
    }

    // ------------------------------------------------------------------ Sources

    /**
     * Register a source of accounts under a name of your choosing.
     *
     * The name is yours; it only ever matters when you register more than one.
     * A class name is built through the container on first use, so a provider
     * with constructor dependencies costs nothing until somebody logs in.
     *
     * @param ProviderInterface|class-string<ProviderInterface> $provider
     */
    public function addProvider(string $name, ProviderInterface|string $provider): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A provider needs a name.');
        }

        if (array_key_exists($name, $this->providers)) {
            throw new InvalidArgumentException('Provider already registered: ' . $name . '.');
        }

        if (is_string($provider) && !is_a($provider, ProviderInterface::class, true)) {
            throw new InvalidArgumentException($provider . ' must implement ' . ProviderInterface::class . '.');
        }

        $this->providers[$name] = $provider;

        return $this;
    }

    public function hasProvider(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * Every registered source, in the order it was added.
     *
     * The registry is here, so asking anywhere else means asking a copy. A source
     * added imperatively in a bootstrap is as real as one that came from
     * configuration, and only this list knows about both.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Reload an account through a registered source, without signing anybody in.
     *
     * Touches no session state: the current login, the loaded model and the store
     * are all left exactly as they were. An unknown source, an empty identifier or
     * a provider that hands back somebody else all answer null — the very guarantee
     * restoration depends on, written once and used by both.
     */
    public function load(string $provider, string $identifier): ?IdentityInterface
    {
        if ($identifier === '' || !$this->hasProvider($provider)) {
            return null;
        }

        $identity = $this->provider($provider)->find($identifier);

        if ($identity === null || $identity->getIdentifier() !== $identifier) {
            return null;
        }

        return self::usable($identity);
    }

    // --------------------------------------------------------- Signing in, out

    /**
     * Verify credentials and sign the person in.
     *
     * False means "not these credentials" and never says which half was wrong.
     * Name the source only when several are registered.
     */
    public function authenticate(#[SensitiveParameter] CredentialsInterface $credentials, ?string $provider = null): bool
    {
        $name     = $provider ?? $this->soleProvider();
        $identity = self::usable($this->provider($name)->authenticate($credentials));

        if ($identity === null) {
            return false;
        }

        $this->setIdentity($identity, $name);

        return true;
    }

    /**
     * Adopt an identity you have already verified yourself.
     *
     * This does not verify credentials. The caller is responsible for trusting
     * the identity before passing it here.
     *
     * Naming a source persists the identity; without one it lasts this request
     * and clears any previous persisted authentication.
     */
    public function setIdentity(IdentityInterface $identity, ?string $provider = null): void
    {
        if ($identity->getIdentifier() === '') {
            throw new InvalidArgumentException('An identity identifier cannot be empty.');
        }

        if (self::usable($identity) === null) {
            // Verified some other way is still not a reason to sign in somebody
            // who may not sign in. Whoever trusted this identity has to check
            // whether the account is open, and this says so rather than letting
            // it through quietly.
            throw new InvalidArgumentException('A suspended account cannot be signed in.');
        }

        if ($provider !== null) {
            $this->provider($provider);
        }

        // Drop the previous person before touching the store: if writing fails,
        // nobody stays signed in on a half-rotated session.
        $this->identity     = null;
        $this->providerName = null;
        $this->restored     = true;

        if ($provider === null) {
            $this->store?->clear();
        } else {
            $this->store?->write($provider, $identity->getIdentifier());
        }

        $this->identity     = $identity;
        $this->providerName = $provider;
    }

    public function logout(): void
    {
        $this->identity     = null;
        $this->providerName = null;
        $this->restored     = true;
        $this->store?->clear();
    }

    /** Forget the loaded identity without logging anybody out. For long-running workers. */
    public function reset(): void
    {
        $this->identity     = null;
        $this->providerName = null;
        $this->restored     = false;
    }

    // -------------------------------------------------------- Who is signed in

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** Your own model, exactly as your provider returned it. Null for guests. */
    public function user(): ?IdentityInterface
    {
        if (!$this->restored) {
            $this->restore();
        }

        return $this->identity;
    }

    public function id(): ?string
    {
        return $this->user()?->getIdentifier();
    }

    /** Which registered source the current person came from. Null for guests. */
    public function providerName(): ?string
    {
        $this->user();

        return $this->providerName;
    }

    // -------------------------------------------------------- What they may do

    /** Every listed permission. Guests are denied; listing none asks only for a login. */
    public function can(string|BackedEnum ...$permissions): bool
    {
        return $this->holds($permissions, roles: false, all: true);
    }

    /** At least one of the listed permissions. */
    public function canAny(string|BackedEnum ...$permissions): bool
    {
        return $this->holds($permissions, roles: false, all: false);
    }

    /** Every listed role. */
    public function hasRole(string|BackedEnum ...$roles): bool
    {
        return $this->holds($roles, roles: true, all: true);
    }

    /** At least one of the listed roles. */
    public function hasAnyRole(string|BackedEnum ...$roles): bool
    {
        return $this->holds($roles, roles: true, all: false);
    }

    /**
     * Ask the rule registered for this object's class.
     *
     * Use it when "may edit" depends on *which* object. The policy owns the whole
     * decision: no policy means no, and a global permission never overrules it.
     */
    public function allows(string|BackedEnum $action, object $resource): bool
    {
        $identity = $this->user();

        if ($identity === null) {
            return false;
        }

        foreach ([$resource::class, ...array_values(class_parents($resource) ?: [])] as $class) {
            if (isset($this->policies[$class])) {
                return ($this->policies[$class])($identity, self::name($action), $resource) === true;
            }
        }

        return false;
    }

    /**
     * Register the rule for one resource class. The exact class wins, then its
     * nearest registered parent.
     *
     * @param class-string $resourceClass
     * @param callable(IdentityInterface, string, object): bool $policy
     */
    public function policy(string $resourceClass, callable $policy): self
    {
        $this->policies[$resourceClass] = $policy;

        return $this;
    }

    // ------------------------------------------------------- Requiring it (401/403)

    /** @throws UnauthenticatedException when nobody is signed in. */
    public function requireLogin(): void
    {
        if ($this->user() === null) {
            throw new UnauthenticatedException();
        }
    }

    /**
     * Every listed permission, or the request stops here.
     *
     * Guests raise 401, a signed-in person missing a grant raises 403. Listing
     * none asks for a login and nothing more, so spreading an empty configured
     * list is safe.
     */
    public function requirePermission(string|BackedEnum ...$permissions): void
    {
        $this->requireLogin();

        if (!$this->can(...$permissions)) {
            throw new ForbiddenException();
        }
    }

    /** Every listed role, or the request stops here. */
    public function requireRole(string|BackedEnum ...$roles): void
    {
        $this->requireLogin();

        if (!$this->hasRole(...$roles)) {
            throw new ForbiddenException();
        }
    }

    // ---------------------------------------------------------------- Internals

    /**
     * The single place where a grant check happens.
     *
     * @param list<string|BackedEnum> $needles
     */
    private function holds(array $needles, bool $roles, bool $all): bool
    {
        $identity = $this->user();

        if ($identity === null) {
            return false;
        }

        if ($needles === []) {
            return $all;
        }

        $wanted = [];
        foreach ($needles as $needle) {
            $wanted[self::name($needle)] = true;
        }

        // One pass over the identity's grants, however many names are asked for:
        // a model that queries a database in getPermissions() pays for one lookup.
        foreach ($roles ? $identity->getRoles() : $identity->getPermissions() as $value) {
            $name = self::name($value);

            if (!isset($wanted[$name])) {
                continue;
            }

            if (!$all) {
                return true;
            }

            unset($wanted[$name]);

            if ($wanted === []) {
                return true;
            }
        }

        return false;
    }

    /** Reload the persisted login. A vanished or swapped account clears the record. */
    private function restore(): void
    {
        $this->restored = true;
        $record         = $this->store?->read();

        if ($record === null) {
            return;
        }

        $identity = $this->load($record['provider'], $record['identifier']);

        if ($identity === null) {
            $this->store?->clear();

            return;
        }

        $this->identity     = $identity;
        $this->providerName = $record['provider'];
    }

    private function provider(string $name): ProviderInterface
    {
        $provider = $this->providers[$name] ?? throw new InvalidArgumentException('Unknown provider: ' . $name . '.');

        if (is_string($provider)) {
            if ($this->factory === null) {
                throw new LogicException('A provider registered by class name needs a provider resolver.');
            }

            $provider = ($this->factory)($provider);

            if (!$provider instanceof ProviderInterface) {
                throw new UnexpectedValueException('A provider must implement ' . ProviderInterface::class . '.');
            }

            $this->providers[$name] = $provider;
        }

        return $provider;
    }

    /** With exactly one source there is nothing to disambiguate. */
    private function soleProvider(): string
    {
        $names = array_keys($this->providers);

        if ($names === []) {
            throw new LogicException('No provider registered. Call auth()->addProvider() in your bootstrap.php first.');
        }

        if (count($names) > 1) {
            throw new LogicException(
                'Several providers are registered (' . implode(', ', $names)
                . '). Name the one you mean: auth()->authenticate($credentials, \'' . $names[0] . '\').',
            );
        }

        return $names[0];
    }

    /**
     * A suspended account is not a signed-in account, whichever door it came
     * through. Asked here rather than in each provider, so a model that says it
     * is closed is closed everywhere — password login, external login, API token
     * and session restoration alike.
     *
     * Models that only implement IdentityInterface are unaffected: without the
     * question there is nothing to answer, and they behave exactly as before.
     */
    private static function usable(?IdentityInterface $identity): ?IdentityInterface
    {
        if ($identity instanceof UserInterface && !$identity->isActive()) {
            return null;
        }

        return $identity;
    }

    private static function name(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
