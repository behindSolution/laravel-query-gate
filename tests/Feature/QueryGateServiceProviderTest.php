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

    public function testRegistersPrettyModelRoutes(): void
    {
        $indexRoute = Route::getRoutes()->getByName('query-gate.model.index');
        $updateRoute = Route::getRoutes()->getByName('query-gate.model.update');

        $this->assertNotNull($indexRoute);
        $this->assertSame('query/{model}', $indexRoute->uri());
        $this->assertContains('GET', $indexRoute->methods());

        $this->assertNotNull($updateRoute);
        $this->assertSame('query/{model}/{id}', $updateRoute->uri());
        $this->assertContains('PATCH', $updateRoute->methods());
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


