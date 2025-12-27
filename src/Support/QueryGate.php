<?php

namespace BehindSolution\LaravelQueryGate\Support;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

class QueryGate implements Arrayable
{
    protected ?Closure $query = null;

    /**
     * @var array<int, string>
     */
    protected array $middleware = [];

    protected ?ActionsBuilder $actions = null;

    protected ?string $paginationMode = null;

    /**
     * @var array{ttl: int, name: string|null}|null
     */
    protected ?array $cache = null;

    /**
     * @var array<string, array<int, string>>
     */
    protected array $filters = [];

    /**
     * @var array<string, Closure>
     */
    protected array $rawFilters = [];

    /**
     * @var array<int, string>
     */
    protected array $select = [];

    public static function make(): self
    {
        return new self();
    }

    public function query(callable $callback): self
    {
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

        if (!in_array($mode, ['paginate', 'cursor', 'none'], true)) {
            throw new InvalidArgumentException('Pagination mode must be paginate, cursor, or none.');
        }

        $this->paginationMode = $mode;

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

        if ($this->filters !== []) {
            $configuration['filters'] = $this->filters;
        }

        if ($this->rawFilters !== []) {
            $configuration['raw_filters'] = $this->rawFilters;
        }

        if ($this->select !== []) {
            $configuration['select'] = $this->select;
        }

        return $configuration;
    }
}


