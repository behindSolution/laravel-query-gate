<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Http\Middleware\ResolveModelMiddleware;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
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
        config()->set('query-gate.model_aliases', [
            'posts' => Post::class,
        ]);

        config()->set('query-gate.models.' . Post::class, QueryGate::make());

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
        $this->expectExceptionMessage('Query Gate model definitions must use QueryGate::make().');

        $middleware->handle($request, static function () {
            return null;
        });
    }

    public function testPopulatesRequestAttributesWhenModelIsExposed(): void
    {
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
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

    public function testResolvesModelAlias(): void
    {
        config()->set('query-gate.model_aliases', [
            'posts' => Post::class,
        ]);

        config()->set('query-gate.models.' . Post::class, QueryGate::make());

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
}


