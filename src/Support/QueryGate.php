<?php

namespace BehindSolution\LaravelQueryGate\Support;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

class QueryGate implements Arrayable
{
    protected bool $isVersion = false;

    protected ?Closure $query = null;

    /**
     * @var array<int, string>
     */
    protected array $middleware = [];

    protected ?ActionsBuilder $actions = null;

    protected ?string $paginationMode = null;

    protected ?string $alias = null;

    /**
     * @var array{ttl: int, name: string|null}|null
     */
    protected ?array $cache = null;

    /**
     * @var array<string, array<int, string>>
     */
    protected array $filters = [];

    /**
     * @var array<string, array<int, string>>
     */
    protected array $allowedFilterOperators = [];

    /**
     * @var array<string, Closure>
     */
    protected array $rawFilters = [];

    /**
     * @var array<int, string>
     */
    protected array $select = [];

    /**
     * @var array<int, string>
     */
    protected array $sorts = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $versions = [];

    /**
     * @var array<int, string>
     */
    protected array $versionOrder = [];

    public function __construct(bool $isVersion = false)
    {
        $this->isVersion = $isVersion;
    }

    public static function make(): self
    {
        return new self();
    }

    public function query(callable $callback): self
    {
        if ($this->isVersion) {
            throw new InvalidArgumentException('Query callbacks must be defined at the root level, outside of version blocks.');
        }

        $this->query = $callback instanceof Closure
            ? $callback
            : Closure::fromCallable($callback);

        return $this;
    }

    /**
     * @param array<int, string> $middleware
     */
    public function middleware(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    public function actions(callable $callback): self
    {
        $builder = new ActionsBuilder();
        $result = $callback($builder);

        if ($result instanceof ActionsBuilder) {
            $builder = $result;
        }

        $this->actions = $builder;

        return $this;
    }

    public function paginationMode(string $mode): self
    {
        $mode = strtolower($mode);

        if (!in_array($mode, ['classic', 'cursor', 'none'], true)) {
            throw new InvalidArgumentException('Pagination mode must be classic, cursor, or none.');
        }

        $this->paginationMode = $mode === 'classic' ? 'classic' : $mode;

        return $this;
    }

    public function cache(int $ttl, ?string $name = null): self
    {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('Cache TTL must be greater than zero.');
        }

        $this->cache = [
            'ttl' => $ttl,
            'name' => is_string($name) && $name !== '' ? $name : null,
        ];

        return $this;
    }

    /**
     * @param array<string, string|array<int, string>> $definitions
     */
    public function filters(array $definitions): self
    {
        $normalized = [];

        foreach ($definitions as $field => $rules) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            if (is_string($rules)) {
                $normalized[$field] = [$rules];
                continue;
            }

            if (is_array($rules)) {
                $normalized[$field] = array_values(array_filter($rules, static function ($rule) {
                    return is_string($rule) && $rule !== '';
                }));
            }
        }

        $this->filters = $normalized;

        return $this;
    }

    /**
     * @param array<string, string|array<int, string>> $operators
     */
    public function allowedFilters(array $operators): self
    {
        $normalized = [];

        foreach ($operators as $field => $values) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            if (is_string($values)) {
                $normalized[$field] = [strtolower($values)];
                continue;
            }

            if (is_array($values)) {
                $normalized[$field] = array_values(array_filter(array_map(static function ($operator) {
                    return is_string($operator) && $operator !== '' ? strtolower($operator) : null;
                }, $values)));
            }
        }

        $this->allowedFilterOperators = $normalized;

        return $this;
    }

    /**
     * @param array<int, string> $columns
     */
    public function select(array $columns): self
    {
        $this->select = array_values(array_filter($columns, static function ($column) {
            return is_string($column) && $column !== '';
        }));

        return $this;
    }

    /**
     * @param array<int, string> $columns
     */
    public function sorts(array $columns): self
    {
        $this->sorts = array_values(array_filter($columns, static function ($column) {
            return is_string($column) && $column !== '';
        }));

        return $this;
    }

    public function alias(string $alias): self
    {
        if ($this->isVersion) {
            throw new InvalidArgumentException('Alias must be defined at the root level, outside of version blocks.');
        }

        $alias = trim($alias);

        if ($alias === '') {
            throw new InvalidArgumentException('Alias must be a non-empty string.');
        }

        $this->alias = $alias;

        return $this;
    }

    /**
     * @param array<string, callable> $callbacks
     */
    public function rawFilters(array $callbacks): self
    {
        $normalized = [];

        foreach ($callbacks as $field => $callback) {
            if (!is_string($field) || $field === '' || !is_callable($callback)) {
                continue;
            }

            $normalized[$field] = $callback instanceof Closure
                ? $callback
                : Closure::fromCallable($callback);
        }

        $this->rawFilters = $normalized;

        return $this;
    }

    public function version(string $identifier, callable $callback): self
    {
        if ($this->isVersion) {
            throw new InvalidArgumentException('Nested versions are not supported.');
        }

        $identifier = trim($identifier);

        if ($identifier === '') {
            throw new InvalidArgumentException('Version identifier must be a non-empty string.');
        }

        $builder = new self(true);
        $callback($builder);

        $definition = $builder->versionDefinition();

        if ($definition === []) {
            return $this;
        }

        $this->versions[$identifier] = $definition;
        $this->versionOrder = array_values(array_filter(
            $this->versionOrder,
            static fn ($value) => $value !== $identifier
        ));
        $this->versionOrder[] = $identifier;

        return $this;
    }

    protected function versionDefinition(): array
    {
        $definition = [];

        if ($this->filters !== []) {
            $definition['filters'] = $this->filters;
        }

        if ($this->allowedFilterOperators !== []) {
            $definition['filter_operators'] = $this->allowedFilterOperators;
        }

        if ($this->rawFilters !== []) {
            $definition['raw_filters'] = $this->rawFilters;
        }

        if ($this->select !== []) {
            $definition['select'] = $this->select;
        }

        if ($this->sorts !== []) {
            $definition['sorts'] = $this->sorts;
        }

        if ($this->actions !== null) {
            $actions = $this->actions->toArray();

            if ($actions !== []) {
                $definition['actions'] = $actions;
            }
        }

        return $definition;
    }

    protected function applyDefaultVersion(array $configuration): array
    {
        if ($this->versions === []) {
            return $configuration;
        }

        $order = $this->versionOrder !== [] ? $this->versionOrder : array_keys($this->versions);
        $default = end($order);

        if ($default === false || !isset($this->versions[$default])) {
            return $configuration;
        }

        $latest = $this->versions[$default];

        foreach ($latest as $key => $value) {
            $configuration[$key] = $value;
        }

        $configuration['versions'] = [
            'definitions' => $this->versions,
            'order' => $order,
            'default' => $default,
            'changelog' => $this->buildChangelog($order),
        ];

        return $configuration;
    }

    /**
     * @param array<int, string> $order
     * @return array<string, array<int, string>>
     */
    protected function buildChangelog(array $order): array
    {
        $timeline = [];
        $previous = null;

        foreach ($order as $identifier) {
            $current = $this->versions[$identifier] ?? [];
            $timeline[$identifier] = $previous === null
                ? []
                : $this->diffVersions($previous, $current);
            $previous = $current;
        }

        return $timeline;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     * @return array<int, string>
     */
    protected function diffVersions(array $previous, array $current): array
    {
        $changes = [];

        $previousFilters = array_keys($previous['filters'] ?? []);
        $currentFilters = array_keys($current['filters'] ?? []);

        foreach (array_diff($currentFilters, $previousFilters) as $filter) {
            $changes[] = sprintf('Added filter: %s', $filter);
        }

        foreach (array_diff($previousFilters, $currentFilters) as $filter) {
            $changes[] = sprintf('Removed filter: %s', $filter);
        }

        $previousOperators = $previous['filter_operators'] ?? [];
        $currentOperators = $current['filter_operators'] ?? [];
        $operatorFields = array_unique(array_merge(
            array_keys($previousOperators),
            array_keys($currentOperators)
        ));

        foreach ($operatorFields as $field) {
            $previousOps = $this->normalizeOperatorList($previousOperators[$field] ?? []);
            $currentOps = $this->normalizeOperatorList($currentOperators[$field] ?? []);

            foreach (array_diff($currentOps, $previousOps) as $operator) {
                $changes[] = sprintf('Added operator: %s.%s', $field, $operator);
            }

            foreach (array_diff($previousOps, $currentOps) as $operator) {
                $changes[] = sprintf('Removed operator: %s.%s', $field, $operator);
            }
        }

        $previousSelect = $this->normalizeStringList($previous['select'] ?? []);
        $currentSelect = $this->normalizeStringList($current['select'] ?? []);

        foreach (array_diff($currentSelect, $previousSelect) as $column) {
            $changes[] = sprintf('Added select: %s', $column);
        }

        foreach (array_diff($previousSelect, $currentSelect) as $column) {
            $changes[] = sprintf('Removed select: %s', $column);
        }

        $previousSorts = $this->normalizeStringList($previous['sorts'] ?? []);
        $currentSorts = $this->normalizeStringList($current['sorts'] ?? []);

        foreach (array_diff($currentSorts, $previousSorts) as $column) {
            $changes[] = sprintf('Added sort: %s', $column);
        }

        foreach (array_diff($previousSorts, $currentSorts) as $column) {
            $changes[] = sprintf('Removed sort: %s', $column);
        }

        return $changes;
    }

    /**
     * @param mixed $operators
     * @return array<int, string>
     */
    protected function normalizeOperatorList($operators): array
    {
        if (is_string($operators) && $operators !== '') {
            return [strtolower($operators)];
        }

        if (is_array($operators)) {
            return array_values(array_filter(array_map(static function ($operator) {
                return is_string($operator) && $operator !== ''
                    ? strtolower($operator)
                    : null;
            }, $operators)));
        }

        return [];
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    protected function normalizeStringList($values): array
    {
        if (is_string($values) && $values !== '') {
            return [$values];
        }

        if (is_array($values)) {
            return array_values(array_filter(array_map(static function ($value) {
                return is_string($value) && $value !== '' ? $value : null;
            }, $values)));
        }

        return [];
    }

    public function toArray(): array
    {
        $configuration = [];

        if ($this->query !== null) {
            $configuration['query'] = $this->query;
        }

        if ($this->middleware !== []) {
            $configuration['middleware'] = $this->middleware;
        }

        if ($this->actions !== null) {
            $actions = $this->actions->toArray();

            if ($actions !== []) {
                $configuration['actions'] = $actions;
            }
        }

        if ($this->paginationMode !== null) {
            $configuration['pagination'] = [
                'mode' => $this->paginationMode,
            ];
        }

        if ($this->cache !== null) {
            $configuration['cache'] = $this->cache;
        }

        if ($this->alias !== null) {
            $configuration['alias'] = $this->alias;
        }

        if ($this->filters !== []) {
            $configuration['filters'] = $this->filters;
        }

        if ($this->allowedFilterOperators !== []) {
            $configuration['filter_operators'] = $this->allowedFilterOperators;
        }

        if ($this->rawFilters !== []) {
            $configuration['raw_filters'] = $this->rawFilters;
        }

        if ($this->select !== []) {
            $configuration['select'] = $this->select;
        }

        if ($this->sorts !== []) {
            $configuration['sorts'] = $this->sorts;
        }

        return $this->applyDefaultVersion($configuration);
    }
}
