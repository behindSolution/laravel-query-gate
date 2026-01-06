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
}


