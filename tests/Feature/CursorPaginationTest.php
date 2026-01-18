<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Query\QueryExecutor;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CursorPaginationTest extends TestCase
{
    public function testCursorPaginationBackwardNavigation(): void
    {
        // Create 10 posts with different created_at values
        $baseTime = Carbon::parse('2026-01-15 10:00:00');

        for ($i = 1; $i <= 10; $i++) {
            Post::query()->create([
                'title' => 'Post ' . $i,
                'status' => 'published',
                'created_at' => $baseTime->copy()->addMinutes($i),
            ]);
        }

        $executor = new QueryExecutor();

        // Page 1: Get first 3 posts (sorted by created_at desc)
        $request1 = Request::create('/query', 'GET', [
            'sort' => 'created_at:desc',
        ]);

        $context1 = new QueryContext(Post::class, $request1, Post::query());

        $page1 = $executor->execute($context1, [
            'pagination' => [
                'mode' => 'cursor',
                'per_page' => 3,
            ],
            'sorts' => ['created_at'],
        ]);

        $this->assertCount(3, $page1);

        // Should have posts 10, 9, 8 (newest first)
        $page1Array = $page1->toArray();
        $this->assertEquals('Post 10', $page1Array['data'][0]['title']);
        $this->assertEquals('Post 9', $page1Array['data'][1]['title']);
        $this->assertEquals('Post 8', $page1Array['data'][2]['title']);

        // Get the next cursor
        $nextCursor = $page1->nextCursor()?->encode();
        $this->assertNotNull($nextCursor, 'Next cursor should not be null');

        // Page 2: Get next 3 posts using cursor
        $request2 = Request::create('/query', 'GET', [
            'sort' => 'created_at:desc',
            'cursor' => $nextCursor,
        ]);

        $context2 = new QueryContext(Post::class, $request2, Post::query());

        $page2 = $executor->execute($context2, [
            'pagination' => [
                'mode' => 'cursor',
                'per_page' => 3,
            ],
            'sorts' => ['created_at'],
        ]);

        $this->assertCount(3, $page2);

        // Should have posts 7, 6, 5
        $page2Array = $page2->toArray();
        $this->assertEquals('Post 7', $page2Array['data'][0]['title']);
        $this->assertEquals('Post 6', $page2Array['data'][1]['title']);
        $this->assertEquals('Post 5', $page2Array['data'][2]['title']);

        // Get the prev cursor
        $prevCursor = $page2->previousCursor()?->encode();
        $this->assertNotNull($prevCursor, 'Prev cursor should not be null');

        // Go back to Page 1 using prev cursor
        $request3 = Request::create('/query', 'GET', [
            'sort' => 'created_at:desc',
            'cursor' => $prevCursor,
        ]);

        $context3 = new QueryContext(Post::class, $request3, Post::query());

        $backToPage1 = $executor->execute($context3, [
            'pagination' => [
                'mode' => 'cursor',
                'per_page' => 3,
            ],
            'sorts' => ['created_at'],
        ]);

        // Should have posts 10, 9, 8 again
        $this->assertCount(3, $backToPage1, 'Back to page 1 should have 3 items');

        $backToPage1Array = $backToPage1->toArray();
        $this->assertEquals('Post 10', $backToPage1Array['data'][0]['title']);
        $this->assertEquals('Post 9', $backToPage1Array['data'][1]['title']);
        $this->assertEquals('Post 8', $backToPage1Array['data'][2]['title']);
    }

    public function testCursorPaginationWithQueryCallback(): void
    {
        // Create 10 posts - 5 published, 5 draft
        $baseTime = Carbon::parse('2026-01-15 10:00:00');

        for ($i = 1; $i <= 10; $i++) {
            Post::query()->create([
                'title' => 'Post ' . $i,
                'status' => $i <= 5 ? 'draft' : 'published',
                'created_at' => $baseTime->copy()->addMinutes($i),
            ]);
        }

        $executor = new QueryExecutor();

        // Page 1: Get first 2 published posts (sorted by created_at desc)
        $request1 = Request::create('/query', 'GET', [
            'sort' => 'created_at:desc',
        ]);

        $context1 = new QueryContext(Post::class, $request1, Post::query());

        $page1 = $executor->execute($context1, [
            'query' => fn ($query) => $query->where('status', 'published'),
            'pagination' => [
                'mode' => 'cursor',
                'per_page' => 2,
            ],
            'sorts' => ['created_at'],
        ]);

        $this->assertCount(2, $page1);

        // Should have posts 10, 9 (newest published first)
        $page1Array = $page1->toArray();
        $this->assertEquals('Post 10', $page1Array['data'][0]['title']);
        $this->assertEquals('Post 9', $page1Array['data'][1]['title']);

        // Get the next cursor
        $nextCursor = $page1->nextCursor()?->encode();
        $this->assertNotNull($nextCursor, 'Next cursor should not be null');

        // Page 2: Get next 2 published posts using cursor
        $request2 = Request::create('/query', 'GET', [
            'sort' => 'created_at:desc',
            'cursor' => $nextCursor,
        ]);

        $context2 = new QueryContext(Post::class, $request2, Post::query());

        $page2 = $executor->execute($context2, [
            'query' => fn ($query) => $query->where('status', 'published'),
            'pagination' => [
                'mode' => 'cursor',
                'per_page' => 2,
            ],
            'sorts' => ['created_at'],
        ]);

        $this->assertCount(2, $page2);

        // Should have posts 8, 7
        $page2Array = $page2->toArray();
        $this->assertEquals('Post 8', $page2Array['data'][0]['title']);
        $this->assertEquals('Post 7', $page2Array['data'][1]['title']);

        // Get the prev cursor
        $prevCursor = $page2->previousCursor()?->encode();
        $this->assertNotNull($prevCursor, 'Prev cursor should not be null');

        // Go back to Page 1 using prev cursor
        $request3 = Request::create('/query', 'GET', [
            'sort' => 'created_at:desc',
            'cursor' => $prevCursor,
        ]);

        $context3 = new QueryContext(Post::class, $request3, Post::query());

        $backToPage1 = $executor->execute($context3, [
            'query' => fn ($query) => $query->where('status', 'published'),
            'pagination' => [
                'mode' => 'cursor',
                'per_page' => 2,
            ],
            'sorts' => ['created_at'],
        ]);

        // Should have posts 10, 9 again
        $this->assertCount(2, $backToPage1, 'Back to page 1 should have 2 items');

        $backToPage1Array = $backToPage1->toArray();
        $this->assertEquals('Post 10', $backToPage1Array['data'][0]['title']);
        $this->assertEquals('Post 9', $backToPage1Array['data'][1]['title']);
    }

    public function testCursorPaginationWithSameCreatedAtValues(): void
    {
        // Create 10 posts with SAME created_at (edge case)
        $sameTime = Carbon::parse('2026-01-15 10:00:00');

        for ($i = 1; $i <= 10; $i++) {
            Post::query()->create([
                'title' => 'Post ' . $i,
                'status' => 'published',
                'created_at' => $sameTime,
            ]);
        }

        $executor = new QueryExecutor();

        // This test verifies behavior when created_at values are identical
        // Laravel cursor pagination should still work using the primary key as tiebreaker
        $request1 = Request::create('/query', 'GET', [
            'sort' => 'created_at:desc',
        ]);

        $context1 = new QueryContext(Post::class, $request1, Post::query());

        $page1 = $executor->execute($context1, [
            'pagination' => [
                'mode' => 'cursor',
                'per_page' => 3,
            ],
            'sorts' => ['created_at'],
        ]);

        $this->assertCount(3, $page1);

        $nextCursor = $page1->nextCursor()?->encode();

        // Page 2
        $request2 = Request::create('/query', 'GET', [
            'sort' => 'created_at:desc',
            'cursor' => $nextCursor,
        ]);

        $context2 = new QueryContext(Post::class, $request2, Post::query());

        $page2 = $executor->execute($context2, [
            'pagination' => [
                'mode' => 'cursor',
                'per_page' => 3,
            ],
            'sorts' => ['created_at'],
        ]);

        $this->assertCount(3, $page2);

        // Verify no duplicate items between pages
        $page1Ids = collect($page1->items())->pluck('id')->toArray();
        $page2Ids = collect($page2->items())->pluck('id')->toArray();

        $this->assertEmpty(array_intersect($page1Ids, $page2Ids), 'Pages should not have duplicate items');
    }
}
