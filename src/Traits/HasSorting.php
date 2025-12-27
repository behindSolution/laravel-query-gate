<?php

namespace BehindSolution\QueryGate\Traits;

use BehindSolution\QueryGate\Concerns\AppliesSorting;
use BehindSolution\QueryGate\Query\QueryContext;
use BehindSolution\QueryGate\Support\SortParser;

trait HasSorting
{
    protected ?SortParser $sortParserInstance = null;

    protected ?AppliesSorting $sortApplierInstance = null;

    protected function applySorting(QueryContext $context): void
    {
        $sortParam = $context->request->query('sort');

        $sorts = $this->sortParser()->parse(is_string($sortParam) ? $sortParam : null);

        if ($sorts === []) {
            return;
        }

        $this->sortApplier()->apply($context->query, $sorts);
    }

    protected function sortParser(): SortParser
    {
        if ($this->sortParserInstance === null) {
            $this->sortParserInstance = new SortParser();
        }

        return $this->sortParserInstance;
    }

    protected function sortApplier(): AppliesSorting
    {
        if ($this->sortApplierInstance === null) {
            $this->sortApplierInstance = new AppliesSorting();
        }

        return $this->sortApplierInstance;
    }
}

