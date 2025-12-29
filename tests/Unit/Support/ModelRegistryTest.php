<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit\Support;

use BehindSolution\LaravelQueryGate\Support\ModelRegistry;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Article;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use InvalidArgumentException;

class ModelRegistryTest extends TestCase
{
    public function testDefinitionsResolveFromTrait(): void
    {
        config()->set('query-gate.models', [
            Article::class,
        ]);

        $registry = app(ModelRegistry::class);

        $definitions = $registry->definitions();

        $this->assertArrayHasKey(Article::class, $definitions);
        $this->assertSame('articles', $definitions[Article::class]['alias']);
        $this->assertSame(['string', 'max:255'], $definitions[Article::class]['filters']['title']);
    }

    public function testAliasMapIncludesTraitConfiguredModel(): void
    {
        config()->set('query-gate.models', [
            Article::class,
        ]);

        $aliases = app(ModelRegistry::class)->aliasMap();

        $this->assertArrayHasKey('articles', $aliases);
        $this->assertSame(Article::class, $aliases['articles']);
    }

    public function testDefinitionsAcceptExplicitQueryGateInstance(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()->alias('posts'));

        $definitions = app(ModelRegistry::class)->definitions();

        $this->assertArrayHasKey(Post::class, $definitions);
        $this->assertSame('posts', $definitions[Post::class]['alias']);
    }

    public function testThrowsWhenModelDoesNotProvideQueryGateDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        config()->set('query-gate.models', [
            Post::class,
        ]);

        app(ModelRegistry::class)->definitions();
    }

    public function testThrowsWhenModelMissingQueryGateMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);

        config()->set('query-gate.models', [
            \stdClass::class,
        ]);

        app(ModelRegistry::class)->definitions();
    }
}


