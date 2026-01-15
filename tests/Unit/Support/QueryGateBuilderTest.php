<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit\Support;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
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
}


