<?php

namespace BehindSolution\LaravelQueryGate\Traits;

use BehindSolution\LaravelQueryGate\Concerns\AppliesPagination;
use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Support\PaginationResolver;

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

        $mode = $context->request->query('pagination');
        if (!is_string($mode) || $mode === '') {
            $mode = $configured['mode'] ?? null;
        }

        $perPage = $context->request->query('per_page');
        if (!is_numeric($perPage) && array_key_exists('per_page', $configured)) {
            $perPage = $configured['per_page'];
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

        return $this->paginationApplier()->apply($context->query, $config);
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

