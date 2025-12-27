<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit;

use BehindSolution\LaravelQueryGate\Actions\ActionExecutor;
use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Query\QueryExecutor;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery;

class ActionExecutorCacheTest extends TestCase
{
    public function testCreateFlushesCachedResults(): void
    {
        $listRequest = Request::create('/query', 'GET');
        $listContext = new QueryContext(
            Post::class,
            $listRequest,
            $this->mockPaginatorReturning('cached-result')
        );

        $configuration = QueryGate::make()
            ->cache(60, 'posts-index')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action->handle(static fn () => 'created'))
            )
            ->toArray();

        $executor = new QueryExecutor();
        $executor->execute($listContext, $configuration);

        $listKey = $this->resolveListKey('posts-index');
        $this->assertTrue(Cache::has($listKey));

        $actionExecutor = new ActionExecutor();

        $createRequest = Request::create('/query', 'POST');
        $createRequest->setUserResolver(static fn () => null);

        $actionExecutor->execute(
            'create',
            $createRequest,
            Post::class,
            $configuration
        );

        $this->assertFalse(Cache::has($listKey));

        $afterFlushBuilder = $this->mockPaginatorReturning('fresh-result');
        $afterFlushContext = new QueryContext(
            Post::class,
            Request::create('/query', 'GET'),
            $afterFlushBuilder
        );

        $fresh = $executor->execute($afterFlushContext, $configuration);

        $this->assertSame('fresh-result', $fresh);
    }

    protected function mockPaginatorReturning($value): Builder
    {
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('paginate')
            ->once()
            ->andReturn($value);

        return $builder;
    }

    protected function resolveListKey(string $name): string
    {
        $reflection = new \ReflectionClass(\BehindSolution\LaravelQueryGate\Support\CacheRegistry::class);
        $method = $reflection->getMethod('listKey');
        $method->setAccessible(true);

        return $method->invoke(null, $name);
    }
}


