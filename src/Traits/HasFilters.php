<?php

namespace BehindSolution\LaravelQueryGate\Traits;

use BehindSolution\LaravelQueryGate\Concerns\AppliesFilters;
use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Support\FilterParser;

trait HasFilters
{
    protected ?FilterParser $filterParserInstance = null;

    protected ?AppliesFilters $filterApplierInstance = null;

    protected function applyFilters(QueryContext $context, array $configuration = []): void
    {
        $rawFilters = (array) $context->request->query('filter', []);
        $definitions = is_array($configuration['filters'] ?? null) ? $configuration['filters'] : [];
        $rawCallbacks = is_array($configuration['raw_filters'] ?? null)
            ? array_filter($configuration['raw_filters'], static fn ($callback) => is_callable($callback))
            : [];
        $allowedOperators = is_array($configuration['filter_operators'] ?? null)
            ? $configuration['filter_operators']
            : [];

        $filters = $this->filterParser()->parse($rawFilters, $definitions, $rawCallbacks, $allowedOperators);

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

