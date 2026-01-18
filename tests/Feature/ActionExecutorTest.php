<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Actions\ActionExecutor;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\PostResource;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\ArchivePostAction;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\CreatePostAction;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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

    public function testCreateReturnsResourceWhenConfigured(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->select(PostResource::class)
            ->actions(fn ($actions) => $actions->create(fn ($action) => $action->validation(['title' => 'required|string'])))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'title' => 'Resource Post',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('create', $request, Post::class, $definition);

        $this->assertInstanceOf(JsonResource::class, $result);
        $this->assertInstanceOf(PostResource::class, $result);

        $resourceData = $result->toArray($request);
        $this->assertArrayHasKey('id', $resourceData);
        $this->assertArrayHasKey('title', $resourceData);
        $this->assertArrayHasKey('formatted_title', $resourceData);
        $this->assertSame('Resource Post', $resourceData['title']);
        $this->assertSame('RESOURCE POST', $resourceData['formatted_title']);
    }

    public function testUpdateReturnsResourceWhenConfigured(): void
    {
        $post = Post::create(['title' => 'Original']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(PostResource::class)
            ->actions(fn ($actions) => $actions->update(fn ($action) => $action->validation(['title' => 'required|string'])))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'PATCH', [
            'title' => 'Updated Title',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('update', $request, Post::class, $definition, (string) $post->id);

        $this->assertInstanceOf(JsonResource::class, $result);
        $this->assertInstanceOf(PostResource::class, $result);

        $resourceData = $result->toArray($request);
        $this->assertArrayHasKey('id', $resourceData);
        $this->assertArrayHasKey('title', $resourceData);
        $this->assertArrayHasKey('formatted_title', $resourceData);
        $this->assertSame('Updated Title', $resourceData['title']);
        $this->assertSame('UPDATED TITLE', $resourceData['formatted_title']);
    }

    public function testResourceIsProperlySerializedInJsonResponse(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->select(PostResource::class)
            ->actions(fn ($actions) => $actions->create(fn ($action) => $action
                ->validation(['title' => 'required|string'])
                ->status(201)
            ))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'title' => 'Json Response Post',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('create', $request, Post::class, $definition);

        $this->assertInstanceOf(PostResource::class, $result);

        // Simulate how the response would be serialized
        $response = $result->toResponse($request);
        $this->assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('Json Response Post', $data['data']['title']);
        $this->assertSame('JSON RESPONSE POST', $data['data']['formatted_title']);
    }

    public function testCustomHandleReturningResourceWorks(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title']) // This should be ignored when handle returns a Resource
            ->actions(fn ($actions) => $actions->create(fn ($action) => $action
                ->validation(['title' => 'required|string'])
                ->handle(function ($request, $model, $payload) {
                    $model->fill($payload);
                    $model->save();

                    return new PostResource($model);
                })
            ))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'title' => 'Custom Handle Resource',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('create', $request, Post::class, $definition);

        $this->assertInstanceOf(JsonResource::class, $result);
        $this->assertInstanceOf(PostResource::class, $result);

        $resourceData = $result->toArray($request);
        $this->assertArrayHasKey('id', $resourceData);
        $this->assertArrayHasKey('title', $resourceData);
        $this->assertArrayHasKey('formatted_title', $resourceData);
        $this->assertSame('Custom Handle Resource', $resourceData['title']);
        $this->assertSame('CUSTOM HANDLE RESOURCE', $resourceData['formatted_title']);
    }

    public function testCustomHandleResourceIgnoresSelectConfig(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id']) // Only id in select, but Resource has more fields
            ->actions(fn ($actions) => $actions->create(fn ($action) => $action
                ->validation(['title' => 'required|string'])
                ->handle(function ($request, $model, $payload) {
                    $model->fill($payload);
                    $model->save();

                    // Return Resource - should include all Resource fields, not just 'id'
                    return new PostResource($model);
                })
            ))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'title' => 'Test Resource Override',
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('create', $request, Post::class, $definition);

        $this->assertInstanceOf(PostResource::class, $result);

        $resourceData = $result->toArray($request);
        // Resource should have all its fields, not limited by select config
        $this->assertArrayHasKey('id', $resourceData);
        $this->assertArrayHasKey('title', $resourceData);
        $this->assertArrayHasKey('formatted_title', $resourceData);
    }

    public function testDetailActionReturnsModel(): void
    {
        $post = Post::create(['title' => 'Test Post']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertSame($post->id, $result['id']);
        $this->assertSame('Test Post', $result['title']);
    }

    public function testDetailActionReturnsOnlySelectColumns(): void
    {
        $post = Post::create(['title' => 'Test Post', 'status' => 'published']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title'])
            ->actions(fn ($actions) => $actions->detail())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertArrayNotHasKey('created_at', $result);
    }

    public function testDetailActionReturnsResourceWhenConfigured(): void
    {
        $post = Post::create(['title' => 'Resource Post']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(PostResource::class)
            ->actions(fn ($actions) => $actions->detail())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);

        $this->assertInstanceOf(JsonResource::class, $result);
        $this->assertInstanceOf(PostResource::class, $result);

        $resourceData = $result->toArray($request);
        $this->assertArrayHasKey('id', $resourceData);
        $this->assertArrayHasKey('title', $resourceData);
        $this->assertArrayHasKey('formatted_title', $resourceData);
        $this->assertSame('Resource Post', $resourceData['title']);
        $this->assertSame('RESOURCE POST', $resourceData['formatted_title']);
    }

    public function testDetailActionRequiresIdentifier(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('A valid identifier is required for this action.');

        $executor->execute('detail', $request, Post::class, $definition);
    }

    public function testDetailActionReturns404WhenNotFound(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Model not found using the provided identifier.');

        $executor->execute('detail', $request, Post::class, $definition, '99999');
    }

    public function testDetailActionWorksWithoutValidation(): void
    {
        $post = Post::create(['title' => 'No Validation Post']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        // Should not throw exception
        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertSame('No Validation Post', $result['title']);
    }

    public function testDetailActionWithCustomHandler(): void
    {
        $post = Post::create(['title' => 'Custom Handler Post']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail(fn ($action) => $action
                ->handle(function ($request, $model, $payload) {
                    return [
                        'custom' => true,
                        'post_id' => $model->id,
                        'post_title' => $model->title,
                    ];
                })
            ))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertTrue($result['custom']);
        $this->assertSame($post->id, $result['post_id']);
        $this->assertSame('Custom Handler Post', $result['post_title']);
    }

    public function testDetailActionRespectsBaseQuery(): void
    {
        $post = Post::create(['title' => 'Query Post', 'status' => 'draft']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->query(fn ($query) => $query->where('status', 'published'))
            ->actions(fn ($actions) => $actions->detail())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Model not found using the provided identifier.');

        // Should not find the post because it's draft, not published
        $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);
    }

    public function testDetailActionThrowsWhenMethodDoesNotMatch(): void
    {
        $post = Post::create(['title' => 'Test Post']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail())
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST'); // Wrong method

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Action "detail" must be invoked using the GET method.');

        $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);
    }

    public function testDetailActionWithCustomSelectOverridesRootSelect(): void
    {
        $post = Post::create(['title' => 'Test Post', 'status' => 'published']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id']) // Root select only has id
            ->actions(fn ($actions) => $actions->detail(fn ($action) => $action
                ->select(['id', 'title', 'status']) // Detail has more columns
            ))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertSame('Test Post', $result['title']);
        $this->assertSame('published', $result['status']);
    }

    public function testDetailActionFallsBackToRootSelectWhenNotSpecified(): void
    {
        $post = Post::create(['title' => 'Test Post', 'status' => 'published']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title']) // Root select
            ->actions(fn ($actions) => $actions->detail()) // No custom select
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayNotHasKey('status', $result); // Uses root select
    }

    public function testDetailActionWithCustomResourceOverridesRootSelect(): void
    {
        $post = Post::create(['title' => 'Resource Post']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id']) // Root select only has id
            ->actions(fn ($actions) => $actions->detail(fn ($action) => $action
                ->select(PostResource::class) // Detail uses Resource
            ))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);

        $this->assertInstanceOf(JsonResource::class, $result);
        $this->assertInstanceOf(PostResource::class, $result);

        $resourceData = $result->toArray($request);
        $this->assertArrayHasKey('id', $resourceData);
        $this->assertArrayHasKey('title', $resourceData);
        $this->assertArrayHasKey('formatted_title', $resourceData);
        $this->assertSame('Resource Post', $resourceData['title']);
        $this->assertSame('RESOURCE POST', $resourceData['formatted_title']);
    }

    public function testDetailActionWithCustomQueryOverridesRootQuery(): void
    {
        $publishedPost = Post::create(['title' => 'Published Post', 'status' => 'published']);
        $draftPost = Post::create(['title' => 'Draft Post', 'status' => 'draft']);

        // Root query filters by published, but detail query allows all
        $definition = QueryGate::make()
            ->alias('posts')
            ->query(fn ($query) => $query->where('status', 'published'))
            ->actions(fn ($actions) => $actions->detail(fn ($action) => $action
                ->query(fn ($query) => $query) // Detail query allows all records
            ))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        // Should find draft post because detail has its own query that allows all
        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $draftPost->id);

        $this->assertIsArray($result);
        $this->assertSame('Draft Post', $result['title']);
    }

    public function testDetailActionFallsBackToRootQueryWhenNotSpecified(): void
    {
        $draftPost = Post::create(['title' => 'Draft Post', 'status' => 'draft']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->query(fn ($query) => $query->where('status', 'published'))
            ->actions(fn ($actions) => $actions->detail()) // No custom query
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Model not found using the provided identifier.');

        // Should not find draft post because it uses root query that filters by published
        $executor->execute('detail', $request, Post::class, $definition, (string) $draftPost->id);
    }

    public function testDetailActionWithCustomSelectAndQuery(): void
    {
        $post = Post::create(['title' => 'Full Detail Post', 'status' => 'draft']);

        $definition = QueryGate::make()
            ->alias('posts')
            ->select(['id'])
            ->query(fn ($query) => $query->where('status', 'published'))
            ->actions(fn ($actions) => $actions->detail(fn ($action) => $action
                ->select(['id', 'title', 'status'])
                ->query(fn ($query) => $query) // Allow all
            ))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'GET');
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('detail', $request, Post::class, $definition, (string) $post->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertSame('Full Detail Post', $result['title']);
        $this->assertSame('draft', $result['status']);
    }
}
