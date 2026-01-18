<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Comment;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Product;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\ApproveCommentAction;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Support\Str;

class AliasWithCrudTest extends TestCase
{
    public function testPatchPostWithAliasAndNumericId(): void
    {
        $post = Post::create(['title' => 'Original Title']);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->update(fn ($action) => $action
                    ->validation(['title' => 'required|string'])
                )
            )
        );

        $response = $this->patchJson("/query/posts/{$post->id}", [
            'title' => 'Updated Title',
        ]);

        $response->assertStatus(200);
        $this->assertSame('Updated Title', $post->fresh()->title);
    }

    public function testDeletePostWithAliasAndNumericId(): void
    {
        $post = Post::create(['title' => 'To Be Deleted']);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->delete())
        );

        $response = $this->deleteJson("/query/posts/{$post->id}");

        $response->assertNoContent();
        $this->assertNull(Post::find($post->id));
    }

    public function testCreateProductWithUuid(): void
    {
        config()->set('query-gate.models.' . Product::class, QueryGate::make()
            ->alias('products')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validation([
                        'name' => 'required|string',
                        'price' => 'required|numeric',
                    ])
                )
            )
        );

        $response = $this->postJson('/query/products', [
            'name' => 'Test Product',
            'price' => 99.99,
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure(['id', 'name', 'price']);

        $data = $response->json();
        $this->assertTrue(Str::isUuid($data['id']));
        $this->assertSame('Test Product', $data['name']);
    }

    public function testPatchProductWithAliasAndUuid(): void
    {
        $product = Product::create([
            'name' => 'Original Product',
            'price' => 50.00,
        ]);

        config()->set('query-gate.models.' . Product::class, QueryGate::make()
            ->alias('products')
            ->actions(fn ($actions) => $actions
                ->update(fn ($action) => $action
                    ->validation([
                        'name' => 'required|string',
                        'price' => 'nullable|numeric',
                    ])
                )
            )
        );

        $response = $this->patchJson("/query/products/{$product->id}", [
            'name' => 'Updated Product',
            'price' => 75.00,
        ]);

        $response->assertStatus(200);
        $this->assertSame('Updated Product', $product->fresh()->name);
        $this->assertEquals(75.00, $product->fresh()->price);
    }

    public function testDeleteProductWithAliasAndUuid(): void
    {
        $product = Product::create([
            'name' => 'Product to Delete',
            'price' => 25.00,
        ]);

        $productId = $product->id;

        config()->set('query-gate.models.' . Product::class, QueryGate::make()
            ->alias('products')
            ->actions(fn ($actions) => $actions->delete())
        );

        $response = $this->deleteJson("/query/products/{$productId}");

        $response->assertNoContent();
        $this->assertNull(Product::find($productId));
    }

    public function testPatchReturns404WhenModelNotFound(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->update(fn ($action) => $action
                    ->validation(['title' => 'required|string'])
                )
            )
        );

        $response = $this->patchJson('/query/posts/99999', [
            'title' => 'Updated Title',
        ]);

        $response->assertStatus(404);
    }

    public function testDeleteReturns404WhenModelNotFound(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->delete())
        );

        $response = $this->deleteJson('/query/posts/99999');

        $response->assertStatus(404);
    }

    public function testPatchProductReturns404WhenUuidNotFound(): void
    {
        $fakeUuid = Str::uuid()->toString();

        config()->set('query-gate.models.' . Product::class, QueryGate::make()
            ->alias('products')
            ->actions(fn ($actions) => $actions
                ->update(fn ($action) => $action
                    ->validation(['name' => 'required|string'])
                )
            )
        );

        $response = $this->patchJson("/query/products/{$fakeUuid}", [
            'name' => 'Updated',
        ]);

        $response->assertStatus(404);
    }

    public function testDeleteProductReturns404WhenUuidNotFound(): void
    {
        $fakeUuid = Str::uuid()->toString();

        config()->set('query-gate.models.' . Product::class, QueryGate::make()
            ->alias('products')
            ->actions(fn ($actions) => $actions->delete())
        );

        $response = $this->deleteJson("/query/products/{$fakeUuid}");

        $response->assertStatus(404);
    }

    public function testCustomActionWithModelReceivesLoadedModel(): void
    {
        $comment = Comment::create(['name' => 'Test Comment']);

        config()->set('query-gate.models.' . Comment::class, QueryGate::make()
            ->alias('comments')
            ->actions(fn ($actions) => $actions->use(ApproveCommentAction::class))
        );

        $response = $this->postJson("/query/comments/{$comment->id}/approve");

        $response->assertStatus(200);
        $response->assertJson([
            'approved' => true,
            'comment_id' => $comment->id,
            'comment_name' => 'Test Comment',
        ]);
    }

    public function testCustomActionWithModelReturns404WhenNotFound(): void
    {
        config()->set('query-gate.models.' . Comment::class, QueryGate::make()
            ->alias('comments')
            ->actions(fn ($actions) => $actions->use(ApproveCommentAction::class))
        );

        $response = $this->postJson('/query/comments/99999/approve');

        $response->assertStatus(404);
    }

    public function testCustomActionWithModelAndUuid(): void
    {
        $product = Product::create([
            'name' => 'Product to Approve',
            'price' => 100.00,
        ]);

        config()->set('query-gate.models.' . Product::class, QueryGate::make()
            ->alias('products')
            ->actions(fn ($actions) => $actions->use(ApproveCommentAction::class))
        );

        $response = $this->postJson("/query/products/{$product->id}/approve");

        $response->assertStatus(200);
        $response->assertJson([
            'approved' => true,
            'comment_id' => $product->id,
            'comment_name' => 'Product to Approve',
        ]);
    }

    public function testCustomActionWithModelReturns405WhenMethodDoesNotMatch(): void
    {
        $comment = Comment::create(['name' => 'Test Comment']);

        config()->set('query-gate.models.' . Comment::class, QueryGate::make()
            ->alias('comments')
            ->actions(fn ($actions) => $actions->use(ApproveCommentAction::class))
        );

        $response = $this->deleteJson("/query/comments/{$comment->id}/approve");

        $response->assertStatus(405);
    }

    public function testDetailPostWithAliasAndNumericId(): void
    {
        $post = Post::create(['title' => 'Detail Test Post']);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail())
        );

        $response = $this->getJson("/query/posts/{$post->id}/detail");

        $response->assertStatus(200);
        $response->assertJsonFragment(['title' => 'Detail Test Post']);
    }

    public function testDetailPostReturnsOnlySelectColumns(): void
    {
        $post = Post::create(['title' => 'Select Test Post', 'status' => 'published']);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title'])
            ->actions(fn ($actions) => $actions->detail())
        );

        $response = $this->getJson("/query/posts/{$post->id}/detail");

        $response->assertStatus(200);
        $response->assertJsonStructure(['id', 'title']);
        $response->assertJsonMissing(['status']);
    }

    public function testDetailReturns404WhenModelNotFound(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail())
        );

        $response = $this->getJson('/query/posts/99999/detail');

        $response->assertStatus(404);
    }

    public function testDetailProductWithAliasAndUuid(): void
    {
        $product = Product::create([
            'name' => 'Detail Product',
            'price' => 150.00,
        ]);

        config()->set('query-gate.models.' . Product::class, QueryGate::make()
            ->alias('products')
            ->actions(fn ($actions) => $actions->detail())
        );

        $response = $this->getJson("/query/products/{$product->id}/detail");

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Detail Product']);
    }

    public function testDetailProductReturns404WhenUuidNotFound(): void
    {
        $fakeUuid = Str::uuid()->toString();

        config()->set('query-gate.models.' . Product::class, QueryGate::make()
            ->alias('products')
            ->actions(fn ($actions) => $actions->detail())
        );

        $response = $this->getJson("/query/products/{$fakeUuid}/detail");

        $response->assertStatus(404);
    }

    public function testDetailReturns405WhenMethodDoesNotMatch(): void
    {
        $post = Post::create(['title' => 'Test Post']);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail())
        );

        $response = $this->postJson("/query/posts/{$post->id}/detail");

        $response->assertStatus(405);
    }

    public function testDetailWithCustomHandler(): void
    {
        $post = Post::create(['title' => 'Custom Detail Post']);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->detail(fn ($action) => $action
                ->handle(fn ($request, $model, $payload) => [
                    'custom_response' => true,
                    'post_title' => $model->title,
                ])
            ))
        );

        $response = $this->getJson("/query/posts/{$post->id}/detail");

        $response->assertStatus(200);
        $response->assertJson([
            'custom_response' => true,
            'post_title' => 'Custom Detail Post',
        ]);
    }

    public function testDetailWithCustomSelectOverridesRootSelect(): void
    {
        $post = Post::create(['title' => 'Detail Post', 'status' => 'published']);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->select(['id']) // Root only has id
            ->actions(fn ($actions) => $actions->detail(fn ($action) => $action
                ->select(['id', 'title', 'status']) // Detail has more
            ))
        );

        $response = $this->getJson("/query/posts/{$post->id}/detail");

        $response->assertStatus(200);
        $response->assertJsonStructure(['id', 'title', 'status']);
        $response->assertJsonFragment(['title' => 'Detail Post', 'status' => 'published']);
    }

    public function testDetailWithCustomQueryOverridesRootQuery(): void
    {
        $draftPost = Post::create(['title' => 'Draft Post', 'status' => 'draft']);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->query(fn ($query) => $query->where('status', 'published')) // Root filters published
            ->actions(fn ($actions) => $actions->detail(fn ($action) => $action
                ->query(fn ($query) => $query) // Detail allows all
            ))
        );

        // Should find draft post because detail has its own query
        $response = $this->getJson("/query/posts/{$draftPost->id}/detail");

        $response->assertStatus(200);
        $response->assertJsonFragment(['title' => 'Draft Post']);
    }

    public function testDetailFallsBackToRootQueryWhenNotSpecified(): void
    {
        $draftPost = Post::create(['title' => 'Draft Post', 'status' => 'draft']);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->query(fn ($query) => $query->where('status', 'published'))
            ->actions(fn ($actions) => $actions->detail()) // No custom query
        );

        // Should not find draft post because it uses root query
        $response = $this->getJson("/query/posts/{$draftPost->id}/detail");

        $response->assertStatus(404);
    }
}
