<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Actions\ActionExecutor;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\ArchivePostAction;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\CreatePostAction;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActionExecutorTest extends TestCase
{
    public function testExecutesClassBasedAction(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(CreatePostAction::class))
            ->toArray();

        config()->set('query-gate.models.' . Post::class, $definition);

        $configuration = $definition;

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'model' => Post::class,
            'title' => 'Sample Title',
        ]);
        $request->headers->set('Accept', 'application/json');

        /** @var JsonResponse $response */
        $response = $executor->execute('create', $request, Post::class, $configuration);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame([
            'handled' => true,
            'payload' => [
                'title' => 'Sample Title',
            ],
        ], $response->getData(true));
    }

    public function testThrowsWhenHttpMethodDoesNotMatch(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(ArchivePostAction::class))
            ->toArray();

        $executor = new ActionExecutor();
        $configuration = $definition;

        $request = Request::create('/query', 'POST', [
            'model' => Post::class,
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Action "archive" must be invoked using the DELETE method.');

        $executor->execute('archive', $request, Post::class, $configuration);
    }

    public function testExecutesCustomActionWithMatchingMethod(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(ArchivePostAction::class))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'DELETE', [
            'model' => Post::class,
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('archive', $request, Post::class, $definition);

        $this->assertSame(['archived' => true], $result);
    }

    public function testThrowsWhenCreateActionHasNoValidation(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->create())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'title' => 'Test',
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('The "create" action requires validation rules.');

        $executor->execute('create', $request, Post::class, $definition);
    }

    public function testThrowsWhenUpdateActionHasNoValidation(): void
    {
        $post = Post::create(['title' => 'Original']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->update())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'PATCH', [
            'title' => 'Updated',
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('The "update" action requires validation rules.');

        $executor->execute('update', $request, Post::class, $definition, (string) $post->id);
    }

    public function testDeleteActionWorksWithoutValidation(): void
    {
        $post = Post::create(['title' => 'To Delete']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->delete())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'DELETE');
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('delete', $request, Post::class, $definition, (string) $post->id);

        $this->assertInstanceOf(\Illuminate\Http\Response::class, $result);
        $this->assertSame(204, $result->getStatusCode());
        $this->assertEmpty($result->getContent());
    }

    public function testCreateWithCustomHandlerWorksWithoutValidation(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(CreatePostAction::class))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'title' => 'Custom Handler',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('create', $request, Post::class, $definition);

        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $result);
        $this->assertSame(202, $result->getStatusCode());
    }

    public function testCreateWithValidationWorks(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->create(fn ($action) => $action->validation(['title' => 'required|string'])))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'title' => 'Valid Title',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('create', $request, Post::class, $definition);

        $this->assertIsArray($result);
        $this->assertSame('Valid Title', $result['title']);
    }

    public function testCreateReturnsOnlySelectColumns(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title'])
            ->actions(fn ($actions) => $actions->create(fn ($action) => $action->validation(['title' => 'required|string'])))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'title' => 'Selected Title',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('create', $request, Post::class, $definition);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayNotHasKey('user_id', $result);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertArrayNotHasKey('created_at', $result);
    }

    public function testUpdateReturnsOnlySelectColumns(): void
    {
        $post = Post::create(['title' => 'Original', 'status' => 'draft']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title'])
            ->actions(fn ($actions) => $actions->update(fn ($action) => $action->validation(['title' => 'required|string'])))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'PATCH', [
            'title' => 'Updated Title',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('update', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertSame('Updated Title', $result['title']);
        $this->assertArrayNotHasKey('user_id', $result);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertArrayNotHasKey('created_at', $result);
    }

    public function testUpdateReturnsQueryComputedFieldsByDefault(): void
    {
        $post = Post::create(['title' => 'Original']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title', 'comments_count'])
            ->query(fn ($query) => $query->withCount('comments'))
            ->actions(fn ($actions) => $actions->update(fn ($action) => $action->validation(['title' => 'required|string'])))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'PATCH', [
            'title' => 'Updated Title',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('update', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('comments_count', $result);
        $this->assertSame(0, $result['comments_count']);
    }

    public function testUpdateWithoutQueryDoesNotIncludeComputedFields(): void
    {
        $post = Post::create(['title' => 'Original']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title', 'comments_count'])
            ->query(fn ($query) => $query->withCount('comments'))
            ->actions(fn ($actions) => $actions->update(fn ($action) => $action
                ->validation(['title' => 'required|string'])
                ->withoutQuery()
            ))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'PATCH', [
            'title' => 'Updated Title',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('update', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        // comments_count should be null because withoutQuery() was used
        $this->assertNull($result['comments_count']);
    }

    public function testCreateReturnsQueryComputedFieldsByDefault(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title', 'comments_count'])
            ->query(fn ($query) => $query->withCount('comments'))
            ->actions(fn ($actions) => $actions->create(fn ($action) => $action->validation(['title' => 'required|string'])))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'title' => 'New Post',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('create', $request, Post::class, $definition);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('comments_count', $result);
        $this->assertSame(0, $result['comments_count']);
    }
}
