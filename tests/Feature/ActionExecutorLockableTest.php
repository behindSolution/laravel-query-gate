<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Actions\ActionExecutor;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\User;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\LockableCustomKeyAction;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\LockableRefundAction;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActionExecutorLockableTest extends TestCase
{
    public function testActionWithoutLockableExecutesNormally(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required|string'])
                    ->handle(fn ($req, $model, $payload) => ['created' => true])
                )
            )
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', ['title' => 'Test']);
        $request->headers->set('Accept', 'application/json');

        $result1 = $executor->execute('create', $request, Post::class, $definition);
        $result2 = $executor->execute('create', $request, Post::class, $definition);

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
    }

    public function testLockableActionExecutesFirstTime(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required|string'])
                    ->handle(fn ($req, $model, $payload) => ['created' => true])
                    ->lockable()
                )
            )
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', ['title' => 'Test']);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('create', $request, Post::class, $definition);

        $this->assertNotNull($result);
    }

    public function testLockableActionThrows409OnDuplicateCall(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required|string'])
                    ->handle(fn ($req, $model, $payload) => ['created' => true])
                    ->lockable(ttl: 60)
                )
            )
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', ['title' => 'Test']);
        $request->headers->set('Accept', 'application/json');

        $executor->execute('create', $request, Post::class, $definition);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Action "create" is already being processed.');

        $executor->execute('create', $request, Post::class, $definition);
    }

    public function testLockableAllowsDifferentPayloads(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required|string'])
                    ->handle(fn ($req, $model, $payload) => ['created' => true])
                    ->lockable(ttl: 60)
                )
            )
            ->toArray();

        $executor = new ActionExecutor();

        $request1 = Request::create('/query', 'POST', ['title' => 'First Post']);
        $request1->headers->set('Accept', 'application/json');

        $request2 = Request::create('/query', 'POST', ['title' => 'Second Post']);
        $request2->headers->set('Accept', 'application/json');

        $result1 = $executor->execute('create', $request1, Post::class, $definition);
        $result2 = $executor->execute('create', $request2, Post::class, $definition);

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
    }

    public function testLockableWithForUserScopesDifferentUsers(): void
    {
        $user1 = User::create(['name' => 'User 1']);
        $user2 = User::create(['name' => 'User 2']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required|string'])
                    ->handle(fn ($req, $model, $payload) => ['created' => true])
                    ->lockable(ttl: 60, forUser: true)
                )
            )
            ->toArray();

        $executor = new ActionExecutor();

        $request1 = Request::create('/query', 'POST', ['title' => 'Test']);
        $request1->headers->set('Accept', 'application/json');
        $request1->setUserResolver(fn () => $user1);

        $request2 = Request::create('/query', 'POST', ['title' => 'Test']);
        $request2->headers->set('Accept', 'application/json');
        $request2->setUserResolver(fn () => $user2);

        $result1 = $executor->execute('create', $request1, Post::class, $definition);
        $result2 = $executor->execute('create', $request2, Post::class, $definition);

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
    }

    public function testLockableWithForUserBlocksSameUser(): void
    {
        $user = User::create(['name' => 'User 1']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required|string'])
                    ->handle(fn ($req, $model, $payload) => ['created' => true])
                    ->lockable(ttl: 60, forUser: true)
                )
            )
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', ['title' => 'Test']);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        $executor->execute('create', $request, Post::class, $definition);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Action "create" is already being processed.');

        $executor->execute('create', $request, Post::class, $definition);
    }

    public function testLockableWithCustomKeyCallback(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required|string'])
                    ->handle(fn ($req, $model, $payload) => ['created' => true])
                    ->lockable(ttl: 60, key: fn ($req, $model, $action, $id) => 'custom-lock-key')
                )
            )
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', ['title' => 'Test']);
        $request->headers->set('Accept', 'application/json');

        $executor->execute('create', $request, Post::class, $definition);

        $this->expectException(HttpException::class);

        $executor->execute('create', $request, Post::class, $definition);
    }

    public function testClassBasedLockableAction(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(LockableRefundAction::class))
            ->toArray();

        $this->assertArrayHasKey('lockable', $definition['actions']['refund']);
        $this->assertSame(10, $definition['actions']['refund']['lockable']['ttl']);
        $this->assertTrue($definition['actions']['refund']['lockable']['forUser']);
    }

    public function testClassBasedLockableActionBlocksDuplicate(): void
    {
        $user = User::create(['name' => 'User 1']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(LockableRefundAction::class))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', ['reason' => 'Defective']);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        $executor->execute('refund', $request, Post::class, $definition);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Action "refund" is already being processed.');

        $executor->execute('refund', $request, Post::class, $definition);
    }

    public function testLockableDefaultTtlIsFiveSeconds(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required|string'])
                    ->handle(fn ($req, $model, $payload) => ['created' => true])
                    ->lockable()
                )
            )
            ->toArray();

        $this->assertSame(5, $definition['actions']['create']['lockable']['ttl']);
    }

    public function testLockableConfigNotPresentWhenNotSet(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required|string'])
                    ->handle(fn ($req, $model, $payload) => ['created' => true])
                )
            )
            ->toArray();

        $this->assertArrayNotHasKey('lockable', $definition['actions']['create']);
    }

    public function testLockableWithUpdateActionAndIdentifier(): void
    {
        $post = Post::create(['title' => 'Original']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->update(fn ($action) => $action
                    ->validations(['title' => 'string'])
                    ->lockable(ttl: 60)
                )
            )
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'PATCH', ['title' => 'Updated']);
        $request->headers->set('Accept', 'application/json');

        $executor->execute('update', $request, Post::class, $definition, (string) $post->id);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Action "update" is already being processed.');

        $executor->execute('update', $request, Post::class, $definition, (string) $post->id);
    }

    public function testLockableUpdateAllowsDifferentIdentifiers(): void
    {
        $post1 = Post::create(['title' => 'Post 1']);
        $post2 = Post::create(['title' => 'Post 2']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->update(fn ($action) => $action
                    ->validations(['title' => 'string'])
                    ->lockable(ttl: 60)
                )
            )
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'PATCH', ['title' => 'Updated']);
        $request->headers->set('Accept', 'application/json');

        $result1 = $executor->execute('update', $request, Post::class, $definition, (string) $post1->id);
        $result2 = $executor->execute('update', $request, Post::class, $definition, (string) $post2->id);

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
    }

    public function testClassBasedLockKeyOverride(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(LockableCustomKeyAction::class))
            ->toArray();

        $this->assertArrayHasKey('lockable', $definition['actions']['charge']);
        $this->assertSame(15, $definition['actions']['charge']['lockable']['ttl']);
        $this->assertArrayHasKey('key', $definition['actions']['charge']['lockable']);
    }

    public function testClassBasedLockKeyOverrideBlocksDuplicate(): void
    {
        $post = Post::create(['title' => 'Test']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(LockableCustomKeyAction::class))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', ['amount' => 100]);
        $request->headers->set('Accept', 'application/json');

        $executor->execute('charge', $request, Post::class, $definition, (string) $post->id);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Action "charge" is already being processed.');

        $executor->execute('charge', $request, Post::class, $definition, (string) $post->id);
    }

    public function testClassBasedLockKeyNullFallsBackToDefault(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(LockableRefundAction::class))
            ->toArray();

        // LockableRefundAction does not override lockKey(), so it returns null
        // The key closure exists but returns null, falling back to default key
        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', ['reason' => 'Defective']);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => User::create(['name' => 'User']));

        $result = $executor->execute('refund', $request, Post::class, $definition);

        $this->assertNotNull($result);
    }
}
