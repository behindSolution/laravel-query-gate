<?php

namespace BehindSolution\LaravelQueryGate\Query;

use BehindSolution\LaravelQueryGate\Support\CacheRegistry;
use BehindSolution\LaravelQueryGate\Traits\HasFilters;
use BehindSolution\LaravelQueryGate\Traits\HasPagination;
use BehindSolution\LaravelQueryGate\Traits\HasSorting;
use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use JsonException;

class QueryExecutor
{
    use HasFilters;
    use HasSorting;
    use HasPagination;

    /**
     * @param array{query?: Closure|null, cache?: array<string, mixed>} $configuration
     * @return mixed
     */
    public function execute(QueryContext $context, array $configuration = [])
    {
        $cacheConfiguration = $this->normalizeCacheConfiguration($configuration['cache'] ?? null, $context);

        if ($cacheConfiguration !== null) {
            $key = $this->buildCacheKey($context, $configuration, $cacheConfiguration);

            return Cache::remember($key, $cacheConfiguration['ttl'], function () use ($context, $configuration, $cacheConfiguration, $key) {
                $result = $this->performExecution($context, $configuration);

                CacheRegistry::register($cacheConfiguration['name'], $key, $cacheConfiguration['ttl']);

                return $result;
            });
        }

        return $this->performExecution($context, $configuration);
    }

    protected function performExecution(QueryContext $context, array $configuration = [])
    {
        $this->applyBaseQuery(
            $context,
            $configuration['query'] ?? null
        );

        $this->applyFilters($context, $configuration);
        $this->applySorting($context, $configuration);

        $result = $this->applyPagination($context, $configuration);

        return $this->applySelection($result, $configuration);
    }

    protected function applyBaseQuery(QueryContext $context, ?Closure $callback): void
    {
        if ($callback === null) {
            return;
        }

        $result = $callback($context->query, $context->request);

        if ($result instanceof Builder) {
            $context->query = $result;
        }
    }

    /**
     * @param array{ttl: int, name: string|null}|null $cache
     * @return array{ttl: int, name: string}|null
     */
    protected function normalizeCacheConfiguration(?array $cache, QueryContext $context): ?array
    {
        if ($cache === null || !isset($cache['ttl'])) {
            return null;
        }

        $ttl = (int) $cache['ttl'];

        if ($ttl <= 0) {
            return null;
        }

        $name = $cache['name'] ?? null;

        if (!is_string($name) || $name === '') {
            $name = $context->model;
        }

        return [
            'ttl' => $ttl,
            'name' => $name,
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array{ttl: int, name: string} $cache
     */
    protected function buildCacheKey(QueryContext $context, array $configuration, array $cache): string
    {
        $request = $context->request;

        $keyData = [
            'name' => $cache['name'],
            'model' => $context->model,
            'path' => $request->getPathInfo(),
            'query' => $this->normalizeArray($request->query()),
            'pagination' => [
                'mode' => $configuration['pagination']['mode'] ?? null,
            ],
            'user' => $this->resolveUserIdentifier($request),
        ];

        try {
            $encoded = json_encode($keyData, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $encoded = serialize($keyData);
        }

        return 'query-gate:cache:' . md5($encoded);
    }

    protected function normalizeArray(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->normalizeArray($value);
            }
        }

        return $values;
    }

    protected function resolveUserIdentifier(Request $request): ?string
    {
        $user = $request->user();

        if (!is_object($user)) {
            return null;
        }

        if (method_exists($user, 'getAuthIdentifier')) {
            $identifier = $user->getAuthIdentifier();

            if ($identifier !== null && $identifier !== '') {
                return (string) $identifier;
            }
        }

        if (isset($user->id)) {
            return (string) $user->id;
        }

        return null;
    }

    /**
     * @param mixed $result
     * @param array<string, mixed> $configuration
     * @return mixed
     */
    protected function applySelection($result, array $configuration)
    {
        $resourceClass = $configuration['resource'] ?? null;

        if (is_string($resourceClass) && class_exists($resourceClass) && is_subclass_of($resourceClass, JsonResource::class)) {
            return $this->applyResourceSelection($result, $resourceClass);
        }

        $select = $configuration['select'] ?? [];

        if ($select === [] || !$this->hasNonEmptyStrings($select)) {
            return $result;
        }

        $tree = $this->buildSelectionTree($select);

        if ($tree === []) {
            return $result;
        }

        if (($result instanceof LengthAwarePaginator)
            || ($result instanceof CursorPaginatorContract)
            || ($result instanceof PaginatorContract)
        ) {
            if (method_exists($result, 'setCollection') && method_exists($result, 'getCollection')) {
                /** @var Collection $collection */
                $collection = $result->getCollection();
                $result->setCollection($this->filterCollection($collection, $tree));
            }

            return $result;
        }

        if ($result instanceof Collection) {
            return $this->filterCollection($result, $tree);
        }

        if ($result instanceof Arrayable) {
            return $this->filterArray($result->toArray(), $tree);
        }

        if (is_array($result)) {
            return $this->filterArray($result, $tree);
        }

        return $result;
    }

    /**
     * Apply a Resource class to transform the result.
     *
     * @param mixed $result
     * @param class-string<JsonResource> $resourceClass
     * @return AnonymousResourceCollection|JsonResource|mixed
     */
    protected function applyResourceSelection($result, string $resourceClass)
    {
        if (($result instanceof LengthAwarePaginator)
            || ($result instanceof CursorPaginatorContract)
            || ($result instanceof PaginatorContract)
        ) {
            return $resourceClass::collection($result);
        }

        if ($result instanceof Collection) {
            return $resourceClass::collection($result);
        }

        if (is_object($result)) {
            return new $resourceClass($result);
        }

        return $result;
    }

    /**
     * @param array<int, string> $select
     */
    protected function buildSelectionTree(array $select): array
    {
        $tree = [];

        foreach ($select as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $segments = explode('.', $path);
            $node =& $tree;

            foreach ($segments as $index => $segment) {
                if ($segment === '') {
                    continue 2;
                }

                if ($index === count($segments) - 1) {
                    $node[$segment] = true;
                } else {
                    if (!isset($node[$segment]) || $node[$segment] === true) {
                        $node[$segment] = [];
                    }

                    $node =& $node[$segment];
                }
            }
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $tree
     */
    protected function filterCollection(Collection $collection, array $tree): Collection
    {
        return $collection->map(function ($item) use ($tree) {
            return $this->normalizeSelectedItem($item, $tree);
        });
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>|mixed
     */
    protected function normalizeSelectedItem($item, array $tree)
    {
        if ($item instanceof Arrayable) {
            $item = $item->toArray();
        }

        if (!is_array($item)) {
            return $item;
        }

        return $this->filterArray($item, $tree);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    protected function filterArray(array $data, array $tree): array
    {
        $filtered = [];

        foreach ($tree as $key => $branch) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if ($branch === true) {
                $filtered[$key] = $value;
                continue;
            }

            if (is_array($branch)) {
                if (is_array($value)) {
                    if ($this->isAssoc($value)) {
                        $filtered[$key] = $this->filterArray($value, $branch);
                    } else {
                        $filtered[$key] = array_map(function ($item) use ($branch) {
                            if ($item instanceof Arrayable) {
                                $item = $item->toArray();
                            }

                            if (is_array($item)) {
                                return $this->filterArray($item, $branch);
                            }

                            return $item;
                        }, $value);
                    }
                }
            }
        }

        return $filtered;
    }

    protected function hasNonEmptyStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    protected function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}

