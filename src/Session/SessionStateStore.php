<?php

declare(strict_types=1);

namespace Naf\Auth\Session;

use Naf\Session\Core\Session;
use RuntimeException;

/** Keeps the login in the injected naf/session session. */
class SessionStateStore implements StateStoreInterface
{
    /** @param list<string> $resetKeys Secrets bound to the previous authentication state. */
    public function __construct(
        private readonly Session $session,
        private readonly string $key = 'auth',
        private readonly array $resetKeys = ['_csrf'],
    ) {
    }

    public function read(): ?array
    {
        $record = $this->session->get($this->key);

        if ($record === null) {
            return null;
        }

        if (!is_array($record)
            || !is_string($record['provider'] ?? null) || $record['provider'] === ''
            || !is_string($record['identifier'] ?? null) || $record['identifier'] === '') {
            $this->clear();

            return null;
        }

        return ['provider' => $record['provider'], 'identifier' => $record['identifier']];
    }

    public function write(string $provider, string $identifier): void
    {
        $session = $this->session;
        $session->forget($this->key);
        $session->start();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Authentication requires an active session.');
        }

        // A login on the guest's session ID is a session fixation waiting to happen,
        // so an ID that refuses to change aborts the login instead of publishing it.
        $previous = session_id();
        $session->regenerate(0);

        if (session_id() === $previous) {
            throw new RuntimeException('Could not rotate the authentication session.');
        }

        $this->forgetSecrets();
        $session->set($this->key, ['provider' => $provider, 'identifier' => $identifier]);
    }

    public function clear(): void
    {
        $this->session->forget($this->key);
        $this->forgetSecrets();

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->session->regenerate(0);
        }
    }

    private function forgetSecrets(): void
    {
        foreach ($this->resetKeys as $key) {
            $this->session->forget($key);
        }
    }
}
