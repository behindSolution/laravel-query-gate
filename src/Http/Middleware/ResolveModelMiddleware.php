<?php

namespace BehindSolution\LaravelQueryGate\Http\Middleware;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveModelMiddleware
{
    public const ATTRIBUTE_MODEL = 'query-gate.model';

    public const ATTRIBUTE_CONFIGURATION = 'query-gate.configuration';

    public const ATTRIBUTE_BUILDER = 'query-gate.builder';

    public function handle(Request $request, Closure $next)
    {
        $modelClass = $this->extractModelClass($request);
        $configuration = $this->resolveConfiguration($modelClass);
        $builder = $this->createBuilder($modelClass);

        $request->attributes->set(self::ATTRIBUTE_MODEL, $modelClass);
        $request->attributes->set(self::ATTRIBUTE_CONFIGURATION, $configuration);
        $request->attributes->set(self::ATTRIBUTE_BUILDER, $builder);

        $middlewares = $this->normalizeMiddleware($configuration['middleware'] ?? []);

        if ($middlewares === []) {
            return $next($request);
        }

        return app(Pipeline::class)
            ->send($request)
            ->through($middlewares)
            ->then(static function ($request) use ($next) {
                return $next($request);
            });
    }

    protected function extractModelClass(Request $request): string
    {
        $modelClass = $request->query('model');

        if (!is_string($modelClass) || $modelClass === '') {
            throw new HttpException(400, 'The model query parameter is required.');
        }

        if (!class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            throw new HttpException(400, 'The model parameter must reference an Eloquent model class.');
        }

        return $modelClass;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveConfiguration(string $modelClass): array
    {
        $definition = config('query-gate.models.' . $modelClass);

        if ($definition === null) {
            throw new HttpException(404, 'The requested model is not exposed through Query Gate.');
        }

        if ($definition instanceof QueryGate) {
            return $definition->toArray();
        }

        throw new HttpException(500, 'Query Gate model definitions must use QueryGate::make().');
    }

    protected function createBuilder(string $modelClass): Builder
    {
        /** @var Model $instance */
        $instance = app($modelClass);

        return $instance->newQuery();
    }

    /**
     * @param array<int, string> $middlewares
     * @return array<int, string|callable>
     */
    protected function normalizeMiddleware(array $middlewares): array
    {
        if ($middlewares === []) {
            return [];
        }

        $router = app('router');
        $resolved = [];

        foreach ($middlewares as $middleware) {
            $resolved[] = MiddlewareNameResolver::resolve(
                $middleware,
                $router->getMiddleware(),
                $router->getMiddlewareGroups()
            );
        }

        return $resolved;
    }
}

