<?php

namespace BehindSolution\LaravelQueryGate\Http\Middleware;

use BehindSolution\LaravelQueryGate\Support\ModelRegistry;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveModelMiddleware
{
    public const ATTRIBUTE_MODEL = 'query-gate.model';

    public const ATTRIBUTE_CONFIGURATION = 'query-gate.configuration';

    public const ATTRIBUTE_BUILDER = 'query-gate.builder';

    protected ModelRegistry $registry;

    public function __construct(ModelRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $definitions = $this->registry->definitions();
        } catch (InvalidArgumentException $exception) {
            throw new HttpException(500, $exception->getMessage(), $exception);
        }

        $modelClass = $this->extractModelClass($request, $definitions);
        $configuration = $this->resolveConfiguration($modelClass, $definitions);
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

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    protected function extractModelClass(Request $request, array $definitions): string
    {
        $identifier = $this->resolveRawModelIdentifier($request);

        [$aliases, $slugs] = $this->aliasIndexes($definitions);

        $model = $aliases[strtolower($identifier)] ?? $identifier;

        if (!class_exists($model) || !is_subclass_of($model, Model::class)) {
            $slug = strtolower(Str::slug($identifier, '-'));
            $model = $slugs[$slug] ?? $model;
        }

        if (!class_exists($model) || !is_subclass_of($model, Model::class)) {
            throw new HttpException(400, 'The model parameter must reference a configured alias or an Eloquent model class.');
        }

        $request->query->set('model', $model);

        return $model;
    }

    protected function resolveRawModelIdentifier(Request $request): string
    {
        $model = $request->route('model');

        if (!is_string($model) || trim($model) === '') {
            $model = $request->query('model');
        }

        if (!is_string($model) || trim($model) === '') {
            throw new HttpException(400, 'The model parameter is required.');
        }

        return trim($model);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveConfiguration(string $modelClass, array $definitions): array
    {
        $definition = $definitions[$modelClass] ?? null;

        if ($definition === null) {
            throw new HttpException(404, 'The requested model is not exposed through Query Gate.');
        }

        if ($definition instanceof QueryGate) {
            return $definition->toArray();
        }

        if (is_array($definition)) {
            return $definition;
        }

        throw new HttpException(500, 'Query Gate model definitions must use QueryGate::make() or the HasQueryGate trait.');
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

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    protected function aliasIndexes(array $definitions): array
    {
        $aliases = [];
        $slugs = [];

        foreach ($definitions as $class => $definition) {
            if (!is_string($class) || $class === '') {
                continue;
            }

            $alias = $definition['alias'] ?? null;

            if (is_string($alias) && $alias !== '') {
                $aliases[strtolower($alias)] = $class;
                $slugs[strtolower(Str::slug($alias, '-'))] = $class;
            }

            $slugs[strtolower(Str::slug($class, '-'))] = $class;
        }

        return [$aliases, $slugs];
    }
}

