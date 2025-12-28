<?php

namespace BehindSolution\LaravelQueryGate;

use BehindSolution\LaravelQueryGate\Console\GenerateSwaggerCommand;
use BehindSolution\LaravelQueryGate\Http\Controllers\QueryGateController;
use BehindSolution\LaravelQueryGate\Http\Middleware\ResolveModelMiddleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class QueryGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/query-gate.php',
            'query-gate'
        );
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMiddlewareAlias();
        $this->registerRoute();
        $this->registerCommands();
    }

    protected function registerMiddlewareAlias(): void
    {
        $this->app['router']->aliasMiddleware(
            'query-gate.resolve-model',
            ResolveModelMiddleware::class
        );
    }

    protected function registerRoute(): void
    {
        $prefix = config('query-gate.route.prefix', 'query');
        $routeMiddleware = (array) config('query-gate.route.middleware', []);
        $middleware = array_merge(
            ['query-gate.resolve-model'],
            $routeMiddleware
        );

        Route::middleware($middleware)
            ->prefix($prefix)
            ->group(function () {
                Route::get('/', [QueryGateController::class, 'index'])
                    ->name('query-gate.index');

                Route::post('/', [QueryGateController::class, 'store'])
                    ->name('query-gate.store');

                Route::patch('/{id}', [QueryGateController::class, 'update'])
                    ->name('query-gate.update');

                Route::delete('/{id}', [QueryGateController::class, 'destroy'])
                    ->name('query-gate.destroy');
            });
    }

    protected function registerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/query-gate.php' => config_path('query-gate.php'),
        ], 'query-gate-config');
    }

    protected function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            GenerateSwaggerCommand::class,
        ]);
    }
}

