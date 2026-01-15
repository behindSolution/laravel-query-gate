<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Actions\ActionExecutor;
use BehindSolution\LaravelQueryGate\Http\Controllers\QueryGateController;
use BehindSolution\LaravelQueryGate\Http\Middleware\ResolveModelMiddleware;
use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Query\QueryExecutor;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Mockery;

class QueryGateControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testIndexDelegatesToQueryExecutor(): void
    {
        $builder = Mockery::mock(Builder::class);

        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, []);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_BUILDER, $builder);

        $executor = Mockery::mock(QueryExecutor::class);
        $executor->shouldReceive('execute')
            ->once()
            ->withArgs(function (QueryContext $context, array $configuration) use ($builder) {
                $this->assertSame(Post::class, $context->model);
                $this->assertSame($builder, $context->query);
                $this->assertSame([], $configuration);

                return true;
            })
            ->andReturn(['listed']);

        $actionExecutor = Mockery::mock(ActionExecutor::class);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->index($request);

        $this->assertSame(['listed'], $result);
    }

    public function testStoreDelegatesToActionExecutor(): void
    {
        $request = Request::create('/query', 'POST', [
            'model' => Post::class,
            'title' => 'Sample',
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'actions' => [
                'create' => [],
            ],
        ]);

        $executor = Mockery::mock(QueryExecutor::class);

        $actionExecutor = Mockery::mock(ActionExecutor::class);
        $actionExecutor->shouldReceive('execute')
            ->once()
            ->with('create', $request, Post::class, [
                'actions' => [
                    'create' => [],
                ],
            ], null)
            ->andReturn(['created' => true]);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->store($request);

        $this->assertSame(['created' => true], $result);
    }

    public function testUpdatePassesIdentifierToActionExecutor(): void
    {
        $request = Request::create('/query/10', 'PATCH', [
            'model' => Post::class,
            'title' => 'Updated',
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'actions' => [
                'update' => [],
            ],
        ]);

        $executor = Mockery::mock(QueryExecutor::class);

        $actionExecutor = Mockery::mock(ActionExecutor::class);
        $actionExecutor->shouldReceive('execute')
            ->once()
            ->with('update', $request, Post::class, [
                'actions' => [
                    'update' => [],
                ],
            ], '10')
            ->andReturn(['updated' => true]);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->update($request, '10');

        $this->assertSame(['updated' => true], $result);
    }

    public function testDestroyDelegatesToActionExecutor(): void
    {
        $request = Request::create('/query/5', 'DELETE', [
            'model' => Post::class,
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'actions' => [
                'delete' => [],
            ],
        ]);

        $executor = Mockery::mock(QueryExecutor::class);

        $actionExecutor = Mockery::mock(ActionExecutor::class);
        $actionExecutor->shouldReceive('execute')
            ->once()
            ->with('delete', $request, Post::class, [
                'actions' => [
                    'delete' => [],
                ],
            ], '5')
            ->andReturn(['deleted' => true]);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->destroy($request, '5');

        $this->assertSame(['deleted' => true], $result);
    }

    public function testChangelogReturnsTimeline(): void
    {
        $request = Request::create('/query/posts/__changelog', 'GET', [
            'model' => Post::class,
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'alias' => 'posts',
        ]);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_VERSION, '2024-01-01');
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_VERSIONS, [
            'definitions' => [
                '2024-01-01' => [],
                '2024-11-01' => [],
            ],
            'order' => ['2024-01-01', '2024-11-01'],
            'default' => '2024-11-01',
            'changelog' => [
                '2024-01-01' => [],
                '2024-11-01' => ['Added filter: created_at'],
            ],
        ]);

        $controller = new QueryGateController(
            Mockery::mock(QueryExecutor::class),
            Mockery::mock(ActionExecutor::class)
        );

        $response = $controller->changelog($request);
        $payload = $response->getData(true);

        $this->assertSame(Post::class, $payload['model']);
        $this->assertSame('posts', $payload['alias']);
        $this->assertSame('2024-11-01', $payload['default']);
        $this->assertSame('2024-01-01', $payload['active']);
        $this->assertSame([
            [
                'version' => '2024-01-01',
                'changes' => [],
            ],
            [
                'version' => '2024-11-01',
                'changes' => ['Added filter: created_at'],
            ],
        ], $payload['versions']);
    }

    public function testChangelogReturnsEmptyTimelineWhenVersionsAreMissing(): void
    {
        $request = Request::create('/query/posts/__changelog', 'GET', [
            'model' => Post::class,
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'alias' => 'posts',
        ]);

        $controller = new QueryGateController(
            Mockery::mock(QueryExecutor::class),
            Mockery::mock(ActionExecutor::class)
        );

        $response = $controller->changelog($request);
        $payload = $response->getData(true);

        $this->assertSame(Post::class, $payload['model']);
        $this->assertSame([], $payload['versions']);
        $this->assertNull($payload['default']);
    }

    public function testPatchOrActionTreatsUuidAsIdentifier(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $request = Request::create("/query/posts/{$uuid}", 'PATCH', [
            'title' => 'Updated',
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'actions' => [
                'update' => [],
                'custom-action' => [],
            ],
        ]);

        $request->setRouteResolver(function () use ($uuid) {
            $route = new \Illuminate\Routing\Route('PATCH', 'query/{model}/{param}', []);
            $route->bind($this->app['request']);
            $route->setParameter('model', 'posts');
            $route->setParameter('param', $uuid);

            return $route;
        });

        $executor = Mockery::mock(QueryExecutor::class);

        $actionExecutor = Mockery::mock(ActionExecutor::class);
        $actionExecutor->shouldReceive('execute')
            ->once()
            ->with('update', $request, Post::class, Mockery::any(), $uuid)
            ->andReturn(['updated' => true]);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->patchOrAction($request);

        $this->assertSame(['updated' => true], $result);
    }

    public function testPatchOrActionExecutesRegisteredCustomAction(): void
    {
        $request = Request::create('/query/posts/publish', 'PATCH');

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'actions' => [
                'update' => [],
                'publish' => ['method' => 'PATCH'],
            ],
        ]);

        $request->setRouteResolver(function () {
            $route = new \Illuminate\Routing\Route('PATCH', 'query/{model}/{param}', []);
            $route->bind($this->app['request']);
            $route->setParameter('model', 'posts');
            $route->setParameter('param', 'publish');

            return $route;
        });

        $executor = Mockery::mock(QueryExecutor::class);

        $actionExecutor = Mockery::mock(ActionExecutor::class);
        $actionExecutor->shouldReceive('execute')
            ->once()
            ->with('publish', $request, Post::class, Mockery::any(), null)
            ->andReturn(['published' => true]);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->patchOrAction($request);

        $this->assertSame(['published' => true], $result);
    }

    public function testPatchOrActionTreatsNumericIdAsIdentifier(): void
    {
        $request = Request::create('/query/posts/42', 'PATCH', [
            'title' => 'Updated',
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'actions' => [
                'update' => [],
            ],
        ]);

        $request->setRouteResolver(function () {
            $route = new \Illuminate\Routing\Route('PATCH', 'query/{model}/{param}', []);
            $route->bind($this->app['request']);
            $route->setParameter('model', 'posts');
            $route->setParameter('param', '42');

            return $route;
        });

        $executor = Mockery::mock(QueryExecutor::class);

        $actionExecutor = Mockery::mock(ActionExecutor::class);
        $actionExecutor->shouldReceive('execute')
            ->once()
            ->with('update', $request, Post::class, Mockery::any(), '42')
            ->andReturn(['updated' => true]);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->patchOrAction($request);

        $this->assertSame(['updated' => true], $result);
    }

    public function testDeleteOrActionTreatsUuidAsIdentifier(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

        $request = Request::create("/query/posts/{$uuid}", 'DELETE');

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'actions' => [
                'delete' => [],
                'archive' => [],
            ],
        ]);

        $request->setRouteResolver(function () use ($uuid) {
            $route = new \Illuminate\Routing\Route('DELETE', 'query/{model}/{param}', []);
            $route->bind($this->app['request']);
            $route->setParameter('model', 'posts');
            $route->setParameter('param', $uuid);

            return $route;
        });

        $executor = Mockery::mock(QueryExecutor::class);

        $actionExecutor = Mockery::mock(ActionExecutor::class);
        $actionExecutor->shouldReceive('execute')
            ->once()
            ->with('delete', $request, Post::class, Mockery::any(), $uuid)
            ->andReturn(['deleted' => true]);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->deleteOrAction($request);

        $this->assertSame(['deleted' => true], $result);
    }

    public function testDeleteOrActionExecutesRegisteredCustomAction(): void
    {
        $request = Request::create('/query/posts/archive', 'DELETE');

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'actions' => [
                'delete' => [],
                'archive' => ['method' => 'DELETE'],
            ],
        ]);

        $request->setRouteResolver(function () {
            $route = new \Illuminate\Routing\Route('DELETE', 'query/{model}/{param}', []);
            $route->bind($this->app['request']);
            $route->setParameter('model', 'posts');
            $route->setParameter('param', 'archive');

            return $route;
        });

        $executor = Mockery::mock(QueryExecutor::class);

        $actionExecutor = Mockery::mock(ActionExecutor::class);
        $actionExecutor->shouldReceive('execute')
            ->once()
            ->with('archive', $request, Post::class, Mockery::any(), null)
            ->andReturn(['archived' => true]);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->deleteOrAction($request);

        $this->assertSame(['archived' => true], $result);
    }

    public function testPatchOrActionTreatsUnregisteredActionNameAsIdentifier(): void
    {
        $request = Request::create('/query/posts/unknown-action', 'PATCH');

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'actions' => [
                'update' => [],
            ],
        ]);

        $request->setRouteResolver(function () {
            $route = new \Illuminate\Routing\Route('PATCH', 'query/{model}/{param}', []);
            $route->bind($this->app['request']);
            $route->setParameter('model', 'posts');
            $route->setParameter('param', 'unknown-action');

            return $route;
        });

        $executor = Mockery::mock(QueryExecutor::class);

        $actionExecutor = Mockery::mock(ActionExecutor::class);
        $actionExecutor->shouldReceive('execute')
            ->once()
            ->with('update', $request, Post::class, Mockery::any(), 'unknown-action')
            ->andReturn(['updated' => true]);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->patchOrAction($request);

        $this->assertSame(['updated' => true], $result);
    }

    public function testIndexReturns403WhenListingIsDisabled(): void
    {
        $builder = Mockery::mock(Builder::class);

        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, [
            'listing_disabled' => true,
        ]);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_BUILDER, $builder);

        $executor = Mockery::mock(QueryExecutor::class);
        $actionExecutor = Mockery::mock(ActionExecutor::class);

        $controller = new QueryGateController($executor, $actionExecutor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Listing is not available for this resource.');

        $controller->index($request);
    }

    public function testIndexAllowsListingWhenNotDisabled(): void
    {
        $builder = Mockery::mock(Builder::class);

        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
        ]);

        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_MODEL, Post::class);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, []);
        $request->attributes->set(ResolveModelMiddleware::ATTRIBUTE_BUILDER, $builder);

        $executor = Mockery::mock(QueryExecutor::class);
        $executor->shouldReceive('execute')
            ->once()
            ->andReturn(['data' => []]);

        $actionExecutor = Mockery::mock(ActionExecutor::class);

        $controller = new QueryGateController($executor, $actionExecutor);

        $result = $controller->index($request);

        $this->assertSame(['data' => []], $result);
    }
}


