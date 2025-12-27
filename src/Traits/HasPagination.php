<?php

namespace BehindSolution\QueryGate\Traits;

use BehindSolution\QueryGate\Concerns\AppliesPagination;
use BehindSolution\QueryGate\Query\QueryContext;
use BehindSolution\QueryGate\Support\PaginationResolver;

trait HasPagination
{
    protected ?PaginationResolver $paginationResolverInstance = null;

    protected ?AppliesPagination $paginationApplierInstance = null;

    /**
     * @return mixed
     */
    protected function applyPagination(QueryContext $context)
    {
        $mode = $context->request->query('pagination');
        $cursor = $context->request->query('cursor');
        $perPage = $context->request->query('per_page');

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

