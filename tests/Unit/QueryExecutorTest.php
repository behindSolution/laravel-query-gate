<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit;

use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Query\QueryExecutor;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\PostResource;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\User;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\UserResource;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
                'posts.comments.name' => function (Builder $query, string $operator, $value, string $column): void {
                    $query->where($column, 'like', '%' . $value . '%');
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

    public function testExecuteRejectsOperatorNotWhitelisted(): void
    {
        Post::query()->create(['title' => 'Sample', 'status' => 'active']);

        $request = Request::create('/query', 'GET', [
            'filter' => [
                'status' => [
                    'like' => 'act',
                ],
            ],
        ]);

        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('The "like" operator is not allowed for the "status" filter.');

        $executor->execute($context, [
            'filters' => [
                'status' => 'string',
            ],
            'filter_operators' => [
                'status' => ['eq'],
            ],
        ]);
    }

    public function testExecuteAllowsWhitelistedOperator(): void
    {
        Post::query()->create(['title' => 'Alpha', 'status' => 'active']);
        Post::query()->create(['title' => 'Beta', 'status' => 'archived']);

        $request = Request::create('/query', 'GET', [
            'filter' => [
                'title' => [
                    'like' => 'alp',
                ],
            ],
        ]);

        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'filters' => [
                'title' => 'string',
            ],
            'filter_operators' => [
                'title' => ['like'],
            ],
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $items = $result->items();
        $this->assertCount(1, $items);
        $this->assertSame('Alpha', $items[0]->title);
    }

    public function testExecuteSupportsNotInOperator(): void
    {
        Post::query()->create(['title' => 'Alpha', 'status' => 'active']);
        Post::query()->create(['title' => 'Beta', 'status' => 'archived']);
        Post::query()->create(['title' => 'Gamma', 'status' => 'draft']);

        $request = Request::create('/query', 'GET', [
            'filter' => [
                'status' => [
                    'not_in' => 'archived,draft',
                ],
            ],
        ]);

        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'filters' => [
                'status' => 'string',
            ],
            'filter_operators' => [
                'status' => ['not_in'],
            ],
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $items = $result->items();
        $this->assertCount(1, $items);
        $this->assertSame('active', $items[0]->status);
    }

    public function testExecuteAppliesAllowedSorts(): void
    {
        Post::query()->create(['title' => 'Alpha', 'status' => 'active']);
        Post::query()->create(['title' => 'Beta', 'status' => 'archived']);

        $request = Request::create('/query', 'GET', [
            'sort' => 'title:desc',
        ]);

        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'sorts' => ['title'],
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $items = $result->items();
        $this->assertCount(2, $items);
        $this->assertSame('Beta', $items[0]->title);
        $this->assertSame('Alpha', $items[1]->title);
    }

    public function testExecuteRejectsSortsNotDeclaredInConfiguration(): void
    {
        $request = Request::create('/query', 'GET', [
            'sort' => 'status:asc',
        ]);

        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Sorting by "status" is not allowed.');

        $executor->execute($context, [
            'sorts' => ['title'],
        ]);
    }

    public function testExecuteReturnsResourceCollectionWhenConfigured(): void
    {
        Post::query()->create(['title' => 'First Post', 'status' => 'published']);
        Post::query()->create(['title' => 'Second Post', 'status' => 'draft']);

        $request = Request::create('/query', 'GET');
        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'resource' => PostResource::class,
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);

        $data = $result->toArray();
        $this->assertCount(2, $data['data']);
        $this->assertArrayHasKey('id', $data['data'][0]);
        $this->assertArrayHasKey('title', $data['data'][0]);
        $this->assertArrayHasKey('formatted_title', $data['data'][0]);
        $this->assertSame('First Post', $data['data'][0]['title']);
        $this->assertSame('FIRST POST', $data['data'][0]['formatted_title']);
    }

    public function testExecuteReturnsResourceCollectionWithPagination(): void
    {
        Post::query()->create(['title' => 'Alpha', 'status' => 'published']);
        Post::query()->create(['title' => 'Beta', 'status' => 'published']);

        $request = Request::create('/query', 'GET');
        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'resource' => PostResource::class,
            'pagination' => [
                'mode' => 'classic',
            ],
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);

        $data = $result->toArray();

        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('current_page', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertCount(2, $data['data']);
        $this->assertSame('ALPHA', $data['data'][0]['formatted_title']);
        $this->assertSame('BETA', $data['data'][1]['formatted_title']);
    }

    public function testExecuteResourceWithFilters(): void
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

        $result = $executor->execute($context, [
            'resource' => PostResource::class,
            'filters' => [
                'status' => 'string',
            ],
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);

        $data = $result->toArray();
        $this->assertCount(1, $data['data']);
        $this->assertSame('Visible', $data['data'][0]['title']);
        $this->assertSame('VISIBLE', $data['data'][0]['formatted_title']);
    }

    public function testResourceTakesPrecedenceOverSelect(): void
    {
        Post::query()->create(['title' => 'Test Post', 'status' => 'active']);

        $request = Request::create('/query', 'GET');
        $context = new QueryContext(Post::class, $request, Post::query());

        $executor = new QueryExecutor();

        // When both resource and select are present, resource should take precedence
        $result = $executor->execute($context, [
            'resource' => PostResource::class,
            'select' => ['id', 'title'], // This should be ignored
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);

        $data = $result->toArray();
        // Resource should include formatted_title which wouldn't be in select
        $this->assertArrayHasKey('formatted_title', $data['data'][0]);
        $this->assertSame('TEST POST', $data['data'][0]['formatted_title']);
    }

    public function testExecuteWithUserResource(): void
    {
        User::query()->create(['name' => 'john doe']);

        $request = Request::create('/query', 'GET');
        $context = new QueryContext(User::class, $request, User::query());

        $executor = new QueryExecutor();

        $result = $executor->execute($context, [
            'resource' => UserResource::class,
        ]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);

        $data = $result->toArray();
        $this->assertCount(1, $data['data']);
        $this->assertSame('john doe', $data['data'][0]['name']);
        $this->assertSame('John doe', $data['data'][0]['display_name']);
    }
}

