<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit\Support;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\PostResource;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\PublishPostAction;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use InvalidArgumentException;

class QueryGateBuilderTest extends TestCase
{
    public function testBuilderTransformsConfigurationIntoArray(): void
    {
        $configuration = QueryGate::make()
            ->filters([
                'created_at' => 'date',
                'posts.title' => ['string', 'max:255'],
            ])
            ->allowedFilters([
                'created_at' => ['eq', 'between'],
                'posts.title' => ['like'],
            ])
            ->select(['created_at', 'posts.title'])
            ->sorts(['created_at'])
            ->alias('users')
            ->rawFilters([
                'posts.title' => fn ($builder, $operator, $value, $column) => null,
            ])
            ->cache(60, 'users')
            ->query(fn () => null)
            ->middleware(['auth'])
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations([
                        'title' => ['required'],
                    ])
                    ->policy('create')
                )
                ->delete(fn ($action) => $action->policy(['delete', 'forceDelete']))
            )
            ->toArray();

        $this->assertArrayHasKey('query', $configuration);
        $this->assertSame(['auth'], $configuration['middleware']);

        $this->assertArrayHasKey('actions', $configuration);
        $this->assertArrayHasKey('create', $configuration['actions']);
        $this->assertArrayHasKey('delete', $configuration['actions']);

        $this->assertSame(
            [
                'method' => 'POST',
                'validation' => ['title' => ['required']],
                'policy' => ['create'],
            ],
            $configuration['actions']['create']
        );
        $this->assertSame(
            [
                'method' => 'DELETE',
                'policy' => ['delete', 'forceDelete'],
            ],
            $configuration['actions']['delete']
        );

        $this->assertArrayHasKey('cache', $configuration);
        $this->assertSame(
            [
                'ttl' => 60,
                'name' => 'users',
            ],
            $configuration['cache']
        );

        $this->assertSame(
            [
                'created_at' => ['date'],
                'posts.title' => ['string', 'max:255'],
            ],
            $configuration['filters']
        );

        $this->assertSame(['created_at', 'posts.title'], $configuration['select']);

        $this->assertArrayHasKey('raw_filters', $configuration);
        $this->assertArrayHasKey('posts.title', $configuration['raw_filters']);
        $this->assertTrue(is_callable($configuration['raw_filters']['posts.title']));

        $this->assertSame(
            [
                'created_at' => ['eq', 'between'],
                'posts.title' => ['like'],
            ],
            $configuration['filter_operators']
        );

        $this->assertSame(['created_at'], $configuration['sorts']);

        $this->assertSame('users', $configuration['alias']);
    }

    public function testStoresPaginationMode(): void
    {
        $configuration = QueryGate::make()
            ->paginationMode('none')
            ->toArray();

        $this->assertArrayHasKey('pagination', $configuration);
        $this->assertSame('none', $configuration['pagination']['mode']);
    }

    public function testRejectsUnknownPaginationMode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryGate::make()->paginationMode('invalid');
    }

    public function testRejectsCacheWithNonPositiveTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryGate::make()->cache(0);
    }

    public function testCacheCanOmitName(): void
    {
        $configuration = QueryGate::make()
            ->cache(30)
            ->toArray();

        $this->assertSame(
            [
                'ttl' => 30,
                'name' => null,
            ],
            $configuration['cache']
        );
    }

    public function testVersionCarriesActions(): void
    {
        $configuration = QueryGate::make()
            ->version('2024-11-01', fn ($builder) => $builder
                ->actions(fn ($actions) => $actions->use(PublishPostAction::class))
            )
            ->toArray();

        $this->assertArrayHasKey('actions', $configuration);
        $this->assertArrayHasKey('publish', $configuration['actions']);
        $this->assertSame(PublishPostAction::class, $configuration['actions']['publish']['class']);
        $this->assertSame('POST', $configuration['actions']['publish']['method']);

        $definitions = $configuration['versions']['definitions']['2024-11-01'] ?? [];
        $this->assertArrayHasKey('actions', $definitions);
        $this->assertArrayHasKey('publish', $definitions['actions']);
    }

    public function testWithoutListingDisablesListing(): void
    {
        $configuration = QueryGate::make()
            ->alias('users')
            ->withoutListing()
            ->toArray();

        $this->assertArrayHasKey('listing_disabled', $configuration);
        $this->assertTrue($configuration['listing_disabled']);
    }

    public function testListingEnabledByDefault(): void
    {
        $configuration = QueryGate::make()
            ->alias('users')
            ->toArray();

        $this->assertArrayNotHasKey('listing_disabled', $configuration);
    }

    public function testWithoutListingRejectsVersionContext(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Listing configuration must be defined at the root level');

        QueryGate::make()
            ->version('2024-01-01', fn ($builder) => $builder->withoutListing());
    }

    public function testSelectAcceptsResourceClass(): void
    {
        $configuration = QueryGate::make()
            ->alias('posts')
            ->select(PostResource::class)
            ->toArray();

        $this->assertArrayHasKey('resource', $configuration);
        $this->assertSame(PostResource::class, $configuration['resource']);
        $this->assertArrayNotHasKey('select', $configuration);
    }

    public function testSelectWithResourceClearsSelectArray(): void
    {
        $configuration = QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title'])
            ->select(PostResource::class)
            ->toArray();

        $this->assertArrayHasKey('resource', $configuration);
        $this->assertSame(PostResource::class, $configuration['resource']);
        $this->assertArrayNotHasKey('select', $configuration);
    }

    public function testSelectWithArrayClearsResource(): void
    {
        $configuration = QueryGate::make()
            ->alias('posts')
            ->select(PostResource::class)
            ->select(['id', 'title'])
            ->toArray();

        $this->assertArrayHasKey('select', $configuration);
        $this->assertSame(['id', 'title'], $configuration['select']);
        $this->assertArrayNotHasKey('resource', $configuration);
    }

    public function testSelectRejectsInvalidResourceClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a valid JsonResource subclass');

        QueryGate::make()->select('InvalidClass');
    }

    public function testSelectRejectsNonResourceClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a valid JsonResource subclass');

        QueryGate::make()->select(\stdClass::class);
    }

    public function testVersionCarriesResource(): void
    {
        $configuration = QueryGate::make()
            ->version('2024-11-01', fn ($builder) => $builder
                ->select(PostResource::class)
            )
            ->toArray();

        $this->assertArrayHasKey('resource', $configuration);
        $this->assertSame(PostResource::class, $configuration['resource']);

        $definitions = $configuration['versions']['definitions']['2024-11-01'] ?? [];
        $this->assertArrayHasKey('resource', $definitions);
        $this->assertSame(PostResource::class, $definitions['resource']);
    }

    public function testOpenapiResponseSetsCustomExamples(): void
    {
        $configuration = QueryGate::make()
            ->select(['id', 'name', 'email'])
            ->openapiResponse([
                'id' => 42,
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ])
            ->toArray();

        $this->assertArrayHasKey('openapi_examples', $configuration);
        $this->assertSame(42, $configuration['openapi_examples']['id']);
        $this->assertSame('John Doe', $configuration['openapi_examples']['name']);
        $this->assertSame('john@example.com', $configuration['openapi_examples']['email']);
    }

    public function testOpenapiResponseWithDotNotation(): void
    {
        $configuration = QueryGate::make()
            ->select(['id', 'tags.id', 'tags.name'])
            ->openapiResponse([
                'id' => 1,
                'tags.id' => 10,
                'tags.name' => 'Technology',
            ])
            ->toArray();

        $this->assertArrayHasKey('openapi_examples', $configuration);
        $this->assertSame(1, $configuration['openapi_examples']['id']);
        $this->assertSame(10, $configuration['openapi_examples']['tags.id']);
        $this->assertSame('Technology', $configuration['openapi_examples']['tags.name']);
    }

    public function testVersionCarriesOpenapiResponseExamples(): void
    {
        $configuration = QueryGate::make()
            ->version('2024-01-01', fn ($builder) => $builder
                ->select(['id', 'title'])
                ->openapiResponse([
                    'id' => 1,
                    'title' => 'Version 1',
                ])
            )
            ->version('2024-06-01', fn ($builder) => $builder
                ->select(['id', 'title', 'status'])
                ->openapiResponse([
                    'id' => 2,
                    'title' => 'Version 2',
                    'status' => 'active',
                ])
            )
            ->toArray();

        // Latest version examples should be at root level
        $this->assertArrayHasKey('openapi_examples', $configuration);
        $this->assertSame(2, $configuration['openapi_examples']['id']);
        $this->assertSame('Version 2', $configuration['openapi_examples']['title']);
        $this->assertSame('active', $configuration['openapi_examples']['status']);

        // Version definitions should also have their examples
        $v1 = $configuration['versions']['definitions']['2024-01-01'] ?? [];
        $this->assertArrayHasKey('openapi_examples', $v1);
        $this->assertSame(1, $v1['openapi_examples']['id']);

        $v2 = $configuration['versions']['definitions']['2024-06-01'] ?? [];
        $this->assertArrayHasKey('openapi_examples', $v2);
        $this->assertSame(2, $v2['openapi_examples']['id']);
    }
}


