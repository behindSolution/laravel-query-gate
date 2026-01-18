<?php

namespace BehindSolution\LaravelQueryGate;

use BehindSolution\LaravelQueryGate\Console\MakeQueryGateActionCommand;
use BehindSolution\LaravelQueryGate\Console\OpenAPICommand;
use BehindSolution\LaravelQueryGate\Http\Controllers\QueryGateController;
use BehindSolution\LaravelQueryGate\Http\Controllers\OpenAPIController;
use BehindSolution\LaravelQueryGate\Http\Middleware\ResolveModelMiddleware;
use BehindSolution\LaravelQueryGate\Support\ModelRegistry;
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

        $this->app->singleton(ModelRegistry::class, function ($app) {
            return new ModelRegistry($app['config']);
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'query-gate');

        $this->registerPublishing();
        $this->registerMiddlewareAlias();
        $this->registerOpenApiRoutes();
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
                Route::get('/{model}/__changelog', [QueryGateController::class, 'changelog'])
                    ->name('query-gate.changelog');

                Route::get('/', [QueryGateController::class, 'index'])
                    ->name('query-gate.index');

                Route::post('/', [QueryGateController::class, 'store'])
                    ->name('query-gate.store');

                Route::get('{model}', [QueryGateController::class, 'index'])
                    ->where('model', '[^/]+')
                    ->name('query-gate.model.index');

                Route::post('{model}', [QueryGateController::class, 'store'])
                    ->where('model', '[^/]+')
                    ->name('query-gate.model.store');

                Route::get('{model}/{id}', [QueryGateController::class, 'show'])
                    ->where([
                        'model' => '[^/]+',
                        'id' => '[^/]+',
                    ])
                    ->name('query-gate.model.show');

                Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], '{model}/{identifier}/{action}', [QueryGateController::class, 'actionWithModel'])
                    ->where([
                        'model' => '[^/]+',
                        'identifier' => '[^/]+',
                        'action' => '[A-Za-z][A-Za-z0-9\-_]*',
                    ])
                    ->name('query-gate.action.with-model');

                Route::match(['GET', 'POST', 'PUT', 'OPTIONS'], '{model}/{action}', [QueryGateController::class, 'action'])
                    ->where([
                        'model' => '[^/]+',
                        'action' => '[A-Za-z][A-Za-z0-9\-_]*',
                    ])
                    ->name('query-gate.action');

                Route::patch('{model}/{param}', [QueryGateController::class, 'patchOrAction'])
                    ->where([
                        'model' => '[^/]+',
                        'param' => '[^/]+',
                    ])
                    ->name('query-gate.model.patch');

                Route::delete('{model}/{param}', [QueryGateController::class, 'deleteOrAction'])
                    ->where([
                        'model' => '[^/]+',
                        'param' => '[^/]+',
                    ])
                    ->name('query-gate.model.delete');

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

        $this->publishes([
            __DIR__ . '/../resources/views/openAPI.blade.php' => resource_path('views/vendor/query-gate/openAPI.blade.php'),
        ], 'query-gate-views');
    }

    protected function registerOpenApiRoutes(): void
    {
        $config = config('query-gate.openAPI');

        if (!is_array($config)) {
            $config = [];
        }

        $uiPath = $this->normalizePath($config['route'] ?? null, 'query/docs');
        $defaultJson = $uiPath === '/'
            ? 'docs.json'
            : trim($uiPath, '/') . '.json';
        $jsonPath = $this->normalizePath($config['json_route'] ?? null, $defaultJson);
        $middleware = array_filter((array) ($config['middleware'] ?? []));

        Route::middleware($middleware)
            ->group(function () use ($uiPath, $jsonPath) {
                Route::get($jsonPath, [OpenAPIController::class, 'json'])
                    ->name('query-gate.openAPI.json');

                Route::get($uiPath, [OpenAPIController::class, 'ui'])
                    ->name('query-gate.openAPI.ui');
            });
    }

    protected function normalizePath($path, string $fallback): string
    {
        $value = is_string($path) ? trim($path) : '';

        if ($value === '') {
            $value = $fallback;
        }

        $value = trim($value, '/');

        if ($value === '') {
            return '/';
        }

        return $value;
    }

    protected function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            MakeQueryGateActionCommand::class,
            OpenAPICommand::class,
        ]);
    }
}

