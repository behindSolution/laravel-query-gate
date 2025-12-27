<?php

namespace BehindSolution\QueryGate\Traits;

use BehindSolution\QueryGate\Concerns\AppliesFilters;
use BehindSolution\QueryGate\Query\QueryContext;
use BehindSolution\QueryGate\Support\FilterParser;

trait HasFilters
{
    protected ?FilterParser $filterParserInstance = null;

    protected ?AppliesFilters $filterApplierInstance = null;

    protected function applyFilters(QueryContext $context): void
    {
        $rawFilters = (array) $context->request->query('filter', []);
        $filters = $this->filterParser()->parse($rawFilters);

        if ($filters === []) {
            return;
        }

        $this->filterApplier()->apply($context->query, $filters);
    }

    protected function filterParser(): FilterParser
    {
        if ($this->filterParserInstance === null) {
            $this->filterParserInstance = new FilterParser();
        }

        return $this->filterParserInstance;
    }

    protected function filterApplier(): AppliesFilters
    {
        if ($this->filterApplierInstance === null) {
            $this->filterApplierInstance = new AppliesFilters();
        }

        return $this->filterApplierInstance;
    }
}

