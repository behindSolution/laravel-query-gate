<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Http\Middleware\ResolveModelMiddleware;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class QueryGateServiceProviderTest extends TestCase
{
    public function testRegistersRouteGroupWithDefaultPrefix(): void
    {
        $route = Route::getRoutes()->getByName('query-gate.index');

        $this->assertNotNull($route);
        $this->assertSame('query', $route->uri());
        $this->assertContains('GET', $route->methods());
    }

    public function testRegistersMiddlewareAlias(): void
    {
        $router = app('router');

        $this->assertArrayHasKey('query-gate.resolve-model', $router->getMiddleware());
        $this->assertSame(
            ResolveModelMiddleware::class,
            $router->getMiddleware()['query-gate.resolve-model']
        );
    }
}


