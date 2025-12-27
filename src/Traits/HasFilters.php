<?php

namespace BehindSolution\LaravelQueryGate\Traits;

use BehindSolution\LaravelQueryGate\Concerns\AppliesFilters;
use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Support\FilterParser;

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

