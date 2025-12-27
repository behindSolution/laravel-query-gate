<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit;

use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Query\QueryExecutor;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Mockery;

class QueryExecutorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testExecuteAppliesFiltersBeforePaginating(): void
    {
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('where')
            ->once()
            ->with('status', '=', 'published')
            ->andReturnSelf();
        $builder->shouldReceive('paginate')
            ->once()
            ->with(15, ['*'], 'page')
            ->andReturn('paginated');

        $request = Request::create('/query', 'GET', [
            'filter' => [
                'status' => [
                    'eq' => 'published',
                ],
            ],
        ]);

        $context = new QueryContext(Post::class, $request, $builder);

        $executor = new QueryExecutor();

        $result = $executor->execute($context);

        $this->assertSame('paginated', $result);
    }
}


