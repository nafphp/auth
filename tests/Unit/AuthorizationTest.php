<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Auth\Auth;
use Naf\Auth\Exceptions\{ForbiddenException, UnauthenticatedException};
use Naf\Auth\Identity\{Identity, IdentityInterface};
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\{CountingIdentity, Permission};

/** Permissions, roles, per-object policies and the 401/403 requirements. */
final class AuthorizationTest extends TestCase
{
    private function signedIn(array $roles = [], array $permissions = []): Auth
    {
        $auth = new Auth();
        $auth->setIdentity(new Identity('42', $roles, $permissions));

        return $auth;
    }

    public function testGuestsAreDeniedEverythingWithoutANullCheck(): void
    {
        $auth = new Auth();

        self::assertFalse($auth->can('posts.edit'));
        self::assertFalse($auth->canAny('posts.edit'));
        self::assertFalse($auth->hasRole('admin'));
        self::assertFalse($auth->hasAnyRole('admin'));
        self::assertFalse($auth->can(), 'Even the empty question is a no for a guest.');
        self::assertFalse($auth->allows('edit', new \stdClass()));
    }

    public function testGrantsMatchExactlyAndAcceptEnums(): void
    {
        $auth = $this->signedIn(permissions: ['posts.edit']);

        self::assertTrue($auth->can('posts.edit'));
        self::assertTrue($auth->can(Permission::PostsEdit));
        self::assertFalse($auth->can('posts.Edit'), 'Matching is case-sensitive.');
        self::assertFalse($auth->can('posts'), 'There is no wildcard and no prefix match.');
        self::assertFalse($auth->can('posts.editor'));
    }

    public function testAllAndAnyAcrossPermissionsAndRoles(): void
    {
        $auth = $this->signedIn(['admin'], ['posts.edit']);

        self::assertTrue($auth->can('posts.edit'));
        self::assertFalse($auth->can('posts.edit', 'posts.publish'));
        self::assertTrue($auth->canAny('posts.edit', 'posts.publish'));
        self::assertFalse($auth->canAny('posts.publish'));

        self::assertTrue($auth->hasRole('admin'));
        self::assertFalse($auth->hasRole('admin', 'editor'));
        self::assertTrue($auth->hasAnyRole('admin', 'editor'));
        self::assertFalse($auth->hasAnyRole('editor'));
    }

    public function testEmptyQuestionsMeanLoggedInVersusNothingAsked(): void
    {
        $auth = $this->signedIn();

        self::assertTrue($auth->can(), 'Nothing required means nothing missing.');
        self::assertTrue($auth->hasRole());
        self::assertFalse($auth->canAny(), 'None of an empty list can ever match.');
        self::assertFalse($auth->hasAnyRole());
    }

    public function testAnIdentityWithoutGrantsSaysNoToEverything(): void
    {
        $auth = $this->signedIn();

        self::assertFalse($auth->can('posts.edit'));
        self::assertFalse($auth->hasRole('admin'));
    }

    public function testGrantsAreReadOnceHoweverManyNamesAreChecked(): void
    {
        $identity = new CountingIdentity('42', ['a', 'b', 'c'], ['admin']);
        $auth = new Auth();
        $auth->setIdentity($identity);

        self::assertTrue($auth->can('a', 'b', 'c'));
        self::assertSame(1, $identity->permissionReads);

        self::assertTrue($auth->canAny('x', 'c'));
        self::assertSame(2, $identity->permissionReads);

        self::assertTrue($auth->hasRole('admin'));
        self::assertSame(1, $identity->roleReads);
        self::assertSame(2, $identity->permissionReads, 'A role check must not touch the permissions.');
    }

    public function testGeneratorGrantsSurviveABatchCheck(): void
    {
        $identity = new CountingIdentity('42', ['posts.edit', 'posts.publish']);
        $auth = new Auth();
        $auth->setIdentity($identity);

        self::assertTrue($auth->can('posts.edit', 'posts.publish'));
        self::assertFalse($auth->can('posts.edit', 'posts.delete'));
    }

    public function testAPolicyOwnsTheWholeDecisionForItsClass(): void
    {
        $post = new Post(authorId: '42');
        $auth = $this->signedIn(permissions: ['posts.edit']);
        $auth->policy(Post::class, fn(IdentityInterface $user, string $action, object $resource): bool
            => $action === 'edit' && $resource instanceof Post && $resource->authorId === $user->getIdentifier());

        self::assertTrue($auth->allows('edit', $post));
        self::assertFalse($auth->allows('delete', $post), 'The global grant never overrules the policy.');
        self::assertFalse($auth->allows('edit', new Post(authorId: '99')));
    }

    public function testWithoutAPolicyTheAnswerIsNo(): void
    {
        $auth = $this->signedIn(permissions: ['posts.edit']);

        self::assertFalse($auth->allows('edit', new Post(authorId: '42')));
    }

    public function testTheMostSpecificPolicyWinsRegardlessOfRegistrationOrder(): void
    {
        $auth = $this->signedIn();
        $auth->policy(Post::class, fn(): bool => false);
        $auth->policy(Article::class, fn(): bool => true);

        self::assertTrue($auth->allows('edit', new Article()));
        self::assertFalse($auth->allows('edit', new Post()));
    }

    public function testAParentPolicyCoversItsSubclasses(): void
    {
        $auth = $this->signedIn();
        $auth->policy(Post::class, fn(): bool => true);

        self::assertTrue($auth->allows('edit', new Article()));
    }

    public function testAPolicyMustReturnTrueToGrant(): void
    {
        $auth = $this->signedIn();
        $auth->policy(Post::class, fn(): mixed => 1);

        self::assertFalse($auth->allows('edit', new Post()), 'Only a real true grants access.');
    }

    public function testGuestsFailEveryRequirementWith401(): void
    {
        $auth = new Auth();

        foreach ([
            fn() => $auth->requireLogin(),
            fn() => $auth->requirePermission('posts.edit'),
            fn() => $auth->requireRole('admin'),
            fn() => $auth->requirePermission(),
        ] as $requirement) {
            try {
                $requirement();
                self::fail('A guest must never pass a requirement.');
            } catch (UnauthenticatedException $exception) {
                self::assertSame(401, $exception->getStatusCode());
            }
        }
    }

    public function testASignedInPersonMissingAGrantGets403(): void
    {
        $auth = $this->signedIn(['editor'], ['posts.edit']);

        foreach ([
            fn() => $auth->requirePermission('posts.publish'),
            fn() => $auth->requirePermission('posts.edit', 'posts.publish'),
            fn() => $auth->requireRole('admin'),
        ] as $requirement) {
            try {
                $requirement();
                self::fail('A missing grant must be forbidden.');
            } catch (ForbiddenException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function testGrantedRequirementsSimplyPass(): void
    {
        $auth = $this->signedIn(['admin'], ['posts.edit', 'posts.publish']);

        $auth->requireLogin();
        $auth->requirePermission('posts.edit', 'posts.publish');
        $auth->requirePermission(Permission::PostsEdit);
        $auth->requireRole('admin');
        $auth->requirePermission();

        self::assertTrue($auth->check());
    }
}

class Post
{
    public function __construct(public string $authorId = '') {}
}

class Article extends Post
{
}
