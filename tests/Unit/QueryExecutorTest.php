<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit;

use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Query\QueryExecutor;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\User;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;

class QueryExecutorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testExecuteAppliesFiltersBeforePaginating(): void
    {
        Post::query()->create(['title' => 'Visible', 'status' => 'published']);
        Post::query()->create(['title' => 'Hidden', 'status' => 'draft']);

        $request = Request::create('/query', 'GET', [
            'filter' => [
                'status' => [
                    'eq' => 'published',
                ],
            ],
        ]);

        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(1, $result->items());
        $this->assertSame('published', $result->items()[0]->status);
    }

    public function testExecuteCachesResultsWhenConfigured(): void
    {
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('paginate')
            ->once()
            ->with(15, ['*'], 'page')
            ->andReturn('cached');

        $request = Request::create('/query', 'GET');
        $context = new QueryContext(Post::class, $request, $builder);

        $executor = new QueryExecutor();

        $configuration = [
            'cache' => [
                'ttl' => 60,
                'name' => 'posts-index',
            ],
        ];

        $first = $executor->execute($context, $configuration);
        $this->assertSame('cached', $first);

        $secondBuilder = Mockery::mock(Builder::class);
        $secondBuilder->shouldReceive('paginate')->never();

        $secondContext = new QueryContext(Post::class, Request::create('/query', 'GET'), $secondBuilder);

        $second = $executor->execute($secondContext, $configuration);

        $this->assertSame('cached', $second);
    }

    public function testExecuteUsesConfiguredPaginationModeWhenRequestOmitsIt(): void
    {
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('cursorPaginate')
            ->once()
            ->with(15, ['*'], 'cursor', null)
            ->andReturn('cursor');

        $request = Request::create('/query', 'GET');

        $context = new QueryContext(Post::class, $request, $builder);

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'pagination' => [
                'mode' => 'cursor',
            ],
        ]);

        $this->assertSame('cursor', $result);
    }

    public function testExecuteRejectsFiltersNotDeclaredInConfiguration(): void
    {
        $request = Request::create('/query', 'GET', [
            'filter' => [
                'forbidden' => [
                    'eq' => 'value',
                ],
            ],
        ]);

        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Filtering by "forbidden" is not allowed.');

        $executor->execute($context, [
            'filters' => [
                'allowed' => 'string',
            ],
        ]);
    }

    public function testExecuteValidatesFilterValueAccordingToRules(): void
    {
        $request = Request::create('/query', 'GET', [
            'filter' => [
                'created_at' => [
                    'eq' => 'not-a-date',
                ],
            ],
        ]);

        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $this->expectException(HttpException::class);

        $executor->execute($context, [
            'filters' => [
                'created_at' => 'date',
            ],
        ]);
    }

    public function testExecuteAppliesRelationFilters(): void
    {
        $user = User::query()->create(['name' => 'Alice']);
        $user->posts()->create(['title' => 'News', 'status' => 'published']);
        $user->posts()->create(['title' => 'Old', 'status' => 'archived']);

        $request = Request::create('/query', 'GET', [
            'filter' => [
                'posts.title' => [
                    'eq' => 'News',
                ],
            ],
        ]);

        $context = new QueryContext(User::class, $request, User::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'filters' => [
                'posts.title' => 'string',
            ],
            'query' => function (Builder $query) {
                return $query->with('posts');
            },
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(1, $result->items());
        $this->assertSame('News', $result->items()[0]->posts->first()->title);
    }

    public function testExecuteAppliesSelectConfiguration(): void
    {
        $user = User::query()->create([
            'name' => 'Alice',
            'created_at' => '2024-01-01 00:00:00',
        ]);

        $user->posts()->create(['title' => 'First', 'status' => 'published']);
        $user->posts()->create(['title' => 'Second', 'status' => 'draft']);

        $request = Request::create('/query', 'GET');
        $context = new QueryContext(User::class, $request, User::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'select' => ['created_at', 'posts.title'],
            'query' => function (Builder $query) {
                return $query->with('posts');
            },
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $items = $result->items();
        $this->assertCount(1, $items);
        $this->assertSame([
            'created_at' => '2024-01-01 00:00:00',
            'posts' => [
                ['title' => 'First'],
                ['title' => 'Second'],
            ],
        ], $items[0]);
    }

    public function testExecuteAppliesNestedRelationFilterWithRawFilter(): void
    {
        $user = User::query()->create(['name' => 'Alice']);
        $post = $user->posts()->create(['title' => 'First', 'status' => 'published']);
        $post->comments()->createMany([
            ['name' => 'Announcement'],
            ['name' => 'Second'],
        ]);

        $request = Request::create('/query', 'GET', [
            'filter' => [
                'posts.comments.name' => [
                    'eq' => 'Announce',
                ],
            ],
        ]);

        $context = new QueryContext(User::class, $request, User::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'filters' => [
                'posts.comments.name' => 'string',
            ],
            'raw_filters' => [
                'posts.comments.name' => function (Builder $query, string $operator, $value): void {
                    $query->where('comments.name', 'like', '%' . $value . '%');
                },
            ],
            'query' => function (Builder $query) {
                return $query->with('posts.comments');
            },
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $items = $result->items();
        $this->assertCount(1, $items);
        $this->assertSame('Alice', $items[0]->name);
        $this->assertTrue(
            $items[0]->posts->first()->comments->contains('name', 'Announcement')
        );
    }
}

