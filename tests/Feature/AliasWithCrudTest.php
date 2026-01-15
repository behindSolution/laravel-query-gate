<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Product;
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
}
