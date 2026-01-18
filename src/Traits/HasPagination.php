<?php

namespace BehindSolution\LaravelQueryGate\Traits;

use BehindSolution\LaravelQueryGate\Concerns\AppliesPagination;
use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Support\PaginationResolver;
use Illuminate\Database\Eloquent\Builder;

trait HasPagination
{
    protected ?PaginationResolver $paginationResolverInstance = null;

    protected ?AppliesPagination $paginationApplierInstance = null;

    /**
     * @return mixed
     */
    protected function applyPagination(QueryContext $context, array $configuration = [])
    {
        $configured = [];

        if (isset($configuration['pagination']) && is_array($configuration['pagination'])) {
            $configured = $configuration['pagination'];
        }

        $mode = $configured['mode'] ?? null;

        $perPage = null;
        if (array_key_exists('per_page', $configured) && is_numeric($configured['per_page'])) {
            $perPage = (int) $configured['per_page'];
        }

        $cursor = $context->request->query('cursor');
        if (!is_string($cursor) || $cursor === '') {
            $cursor = $configured['cursor'] ?? null;
        }

        $config = $this->paginationResolver()->resolve(
            is_string($mode) ? $mode : null,
            $this->normalizePerPage($perPage),
            is_string($cursor) ? $cursor : null
        );

        // Add primary key as tiebreaker for cursor pagination
        if ($config['type'] === 'cursor') {
            $this->ensurePrimaryKeyInOrder($context->query);
        }

        return $this->paginationApplier()->apply($context->query, $config);
    }

    /**
     * Ensure the primary key is included in the ORDER BY clause for cursor pagination.
     * This prevents issues when multiple records have the same value for the sort column.
     */
    protected function ensurePrimaryKeyInOrder(Builder $query): void
    {
        $model = $query->getModel();
        $keyName = $model->getKeyName();
        $qualifiedKeyName = $model->qualifyColumn($keyName);

        // Check if primary key is already in the order
        $orders = $query->getQuery()->orders ?? [];

        foreach ($orders as $order) {
            $column = $order['column'] ?? null;

            if ($column === $keyName || $column === $qualifiedKeyName) {
                return;
            }
        }

        // Determine direction based on existing orders (default to desc if no orders)
        $direction = 'desc';
        if (!empty($orders)) {
            $lastOrder = end($orders);
            $direction = $lastOrder['direction'] ?? 'desc';
        }

        $query->orderBy($qualifiedKeyName, $direction);
    }

    protected function paginationResolver(): PaginationResolver
    {
        if ($this->paginationResolverInstance === null) {
            $default = (int) config('query-gate.pagination.per_page', 15);
            $max = (int) config('query-gate.pagination.max_per_page', 100);

            $this->paginationResolverInstance = new PaginationResolver($default, $max);
        }

        return $this->paginationResolverInstance;
    }

    protected function paginationApplier(): AppliesPagination
    {
        if ($this->paginationApplierInstance === null) {
            $this->paginationApplierInstance = new AppliesPagination();
        }

        return $this->paginationApplierInstance;
    }

    /**
     * @param mixed $perPage
     */
    protected function normalizePerPage($perPage): ?int
    {
        if (is_numeric($perPage)) {
            return (int) $perPage;
        }

        return null;
    }
}

