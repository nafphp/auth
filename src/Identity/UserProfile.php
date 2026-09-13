<?php

declare(strict_types=1);

namespace NixPHP\Auth\Identity;

/**
 * The three things about a person this framework is willing to pass on.
 *
 * Deliberately not "whatever the model happens to hold". A profile crosses
 * boundaries — into an ID token, into a UserInfo answer, into a consent screen —
 * and every field that crosses one has to have been put here on purpose. A
 * getter that reflected the model would export the next column somebody adds.
 *
 * Identity stays in IdentityInterface: the identifier, the roles, the
 * permissions. Those answer "who is this and what may they do"; this answers
 * "what may be shown about them", and the two are not the same question.
 */
final readonly class UserProfile
{
    /**
     * @param bool $emailVerified Whether the address was actually confirmed —
     *                            never "we have one, so presumably yes".
     */
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public bool $emailVerified = false,
    ) {}

    /**
     * The OpenID Connect claims this profile stands for.
     *
     * Only what is actually known: a missing name is absent rather than empty,
     * because a relying party can tell the difference and should be allowed to.
     *
     * @return array<string, string|bool>
     */
    public function claims(): array
    {
        $claims = [];

        if ($this->name !== null && $this->name !== '') {
            $claims['name'] = $this->name;
        }

        if ($this->email !== null && $this->email !== '') {
            $claims['email']          = $this->email;
            $claims['email_verified'] = $this->emailVerified;
        }

        return $claims;
    }
}
