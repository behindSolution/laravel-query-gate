<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit\Support;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
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
            ->select(['created_at', 'posts.title'])
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
                'validation' => ['title' => ['required']],
                'policy' => ['create'],
            ],
            $configuration['actions']['create']
        );
        $this->assertSame(
            ['policy' => ['delete', 'forceDelete']],
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
}


