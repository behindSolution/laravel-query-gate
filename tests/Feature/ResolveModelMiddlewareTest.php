<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Http\Middleware\ResolveModelMiddleware;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Article;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveModelMiddlewareTest extends TestCase
{
    public function testThrowsWhenModelParameterIsMissing(): void
    {
        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('The model parameter is required.');

        $middleware->handle($request, static function () {
            return null;
        });
    }

    public function testResolvesModelFromRouteParameter(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()->alias('posts'));

        $middleware = app(ResolveModelMiddleware::class);

        $request = Request::create('/query/posts', 'GET');

        $request->setRouteResolver(static function () {
            return new class {
                public function parameter(string $key, $default = null)
                {
                    if ($key === 'model') {
                        return 'posts';
                    }

                    return $default;
                }
            };
        });

        $dispatched = false;
        $response = $middleware->handle($request, static function ($handledRequest) use (&$dispatched) {
            $dispatched = true;

            return response()->noContent();
        });

        $this->assertTrue($dispatched);
        $this->assertSame(Post::class, $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_MODEL));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testThrowsWhenModelDoesNotExist(): void
    {
        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => 'NonExistingClass',
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('The model parameter must reference a configured alias or an Eloquent model class.');

        $middleware->handle($request, static function () {
            return null;
        });
    }

    public function testThrowsWhenModelIsNotExposed(): void
    {
        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('The requested model is not exposed through Query Gate.');

        $middleware->handle($request, static function () {
            return null;
        });
    }

    public function testThrowsWhenLegacyArrayConfigurationIsUsed(): void
    {
        config()->set('query-gate.models.' . Post::class, []);

        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage(sprintf(
            'Query Gate definition for [%s] must be provided via QueryGate::make() or the HasQueryGate trait.',
            Post::class
        ));

        $middleware->handle($request, static function () {
            return null;
        });
    }

    public function testPopulatesRequestAttributesWhenModelIsExposed(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->delete())
        );

        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
        ]);

        $dispatched = false;
        $response = $middleware->handle($request, static function ($handledRequest) use (&$dispatched) {
            $dispatched = true;

            return response()->noContent();
        });

        $this->assertTrue($dispatched);
        $this->assertSame(Post::class, $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_MODEL));
        $this->assertIsArray($request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION));
        $this->assertNotNull($request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_BUILDER));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testResolvesTraitConfiguredModel(): void
    {
        config()->set('query-gate.models', [
            Article::class,
        ]);

        $middleware = app(ResolveModelMiddleware::class);

        $request = Request::create('/query/articles', 'GET');

        $request->setRouteResolver(static function () {
            return new class {
                public function parameter(string $key, $default = null)
                {
                    if ($key === 'model') {
                        return 'articles';
                    }

                    return $default;
                }
            };
        });

        $response = $middleware->handle($request, static function ($handledRequest) {
            return response()->noContent();
        });

        $configuration = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(Article::class, $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_MODEL));
        $this->assertIsArray($configuration);
        $this->assertSame('articles', $configuration['alias']);
        $this->assertSame(['string', 'max:255'], $configuration['filters']['title']);
    }

    public function testResolvesModelAlias(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()->alias('posts'));

        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => 'Posts',
        ]);

        $dispatched = false;
        $response = $middleware->handle($request, static function ($handledRequest) use (&$dispatched) {
            $dispatched = true;

            return response()->noContent();
        });

        $this->assertTrue($dispatched);
        $this->assertSame(Post::class, $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_MODEL));
        $this->assertIsArray($request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION));
        $this->assertNotNull($request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_BUILDER));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testAppliesLatestVersionWhenNoVersionIsRequested(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->version('2024-01-01', function (QueryGate $gate) {
                $gate->select(['id']);
            })
            ->version('2024-11-01', function (QueryGate $gate) {
                $gate->select(['id', 'title']);
            })
        );

        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
        ]);

        $middleware->handle($request, static function () {
            return response()->noContent();
        });

        $configuration = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION);
        $versions = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_VERSIONS);

        $this->assertSame(['id', 'title'], $configuration['select']);
        $this->assertSame('2024-11-01', $configuration['active_version']);
        $this->assertIsArray($versions);
        $this->assertSame('2024-11-01', $versions['default']);
    }

    public function testUsesVersionProvidedByHeader(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->version('2024-01-01', function (QueryGate $gate) {
                $gate->select(['id']);
            })
            ->version('2024-11-01', function (QueryGate $gate) {
                $gate->select(['id', 'title']);
            })
        );

        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
            'version' => '2024-11-01',
        ]);
        $request->headers->set('X-Query-Version', '2024-01-01');

        $middleware->handle($request, static function () {
            return response()->noContent();
        });

        $configuration = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION);

        $this->assertSame(['id'], $configuration['select']);
        $this->assertSame('2024-01-01', $configuration['active_version']);
    }

    public function testFallsBackToQueryParameterWhenHeaderIsMissing(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->version('2024-01-01', function (QueryGate $gate) {
                $gate->select(['id']);
            })
            ->version('2024-11-01', function (QueryGate $gate) {
                $gate->select(['id', 'title']);
            })
        );

        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
            'version' => '2024-01-01',
        ]);

        $middleware->handle($request, static function () {
            return response()->noContent();
        });

        $configuration = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION);

        $this->assertSame(['id'], $configuration['select']);
        $this->assertSame('2024-01-01', $configuration['active_version']);
    }

    public function testThrowsWhenRequestedVersionDoesNotExist(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->version('2024-11-01', function (QueryGate $gate) {
                $gate->select(['id', 'title']);
            })
        );

        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
        ]);
        $request->headers->set('X-Query-Version', '2024-01-01');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Version "2024-01-01" is not available for this resource.');

        $middleware->handle($request, static function () {
            return response()->noContent();
        });
    }

    public function testThrowsWhenModelIsRegisteredWithoutQueryGateMethod(): void
    {
        config()->set('query-gate.models', [
            Post::class,
        ]);

        $middleware = app(ResolveModelMiddleware::class);
        $request = Request::create('/query', 'GET', [
            'model' => Post::class,
        ]);
        $request->headers->set('Accept', 'application/json');

        $response = $middleware->handle($request, static function () {
            return response()->noContent();
        });

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([
            'message' => sprintf(
                'Invalid Query Gate configuration for [%s]: Model [%s] must define a static queryGate() method. Use the HasQueryGate trait or provide a custom implementation.',
                Post::class,
                Post::class
            ),
        ], $response->getData(true));
    }

    public function testResolvesModelFromRequestBody(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()->alias('posts'));

        $middleware = app(ResolveModelMiddleware::class);

        $request = Request::create('/query', 'PATCH', [], [], [], [], json_encode([
            'model' => 'posts',
            'title' => 'Updated Title',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dispatched = false;
        $response = $middleware->handle($request, static function ($handledRequest) use (&$dispatched) {
            $dispatched = true;

            return response()->noContent();
        });

        $this->assertTrue($dispatched);
        $this->assertSame(Post::class, $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_MODEL));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testResolvesModelNamespaceFromRequestBody(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()->alias('posts'));

        $middleware = app(ResolveModelMiddleware::class);

        $request = Request::create('/query', 'PATCH', [], [], [], [], json_encode([
            'model' => Post::class,
            'title' => 'Updated Title',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dispatched = false;
        $response = $middleware->handle($request, static function ($handledRequest) use (&$dispatched) {
            $dispatched = true;

            return response()->noContent();
        });

        $this->assertTrue($dispatched);
        $this->assertSame(Post::class, $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_MODEL));
        $this->assertSame(204, $response->getStatusCode());
    }
}


