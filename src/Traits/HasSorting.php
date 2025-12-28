<?php

namespace BehindSolution\LaravelQueryGate\Traits;

use BehindSolution\LaravelQueryGate\Concerns\AppliesSorting;
use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Support\SortParser;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait HasSorting
{
    protected ?SortParser $sortParserInstance = null;

    protected ?AppliesSorting $sortApplierInstance = null;

    protected function applySorting(QueryContext $context, array $configuration = []): void
    {
        $sortParam = $context->request->query('sort');

        $sorts = $this->sortParser()->parse(is_string($sortParam) ? $sortParam : null);

        if ($sorts === []) {
            return;
        }

        $allowedSorts = array_values(array_filter(
            is_array($configuration['sorts'] ?? null) ? $configuration['sorts'] : [],
            static fn ($value) => is_string($value) && $value !== ''
        ));

        if ($allowedSorts !== []) {
            foreach ($sorts as $sort) {
                if (!in_array($sort['field'], $allowedSorts, true)) {
                    throw new HttpException(422, sprintf('Sorting by "%s" is not allowed.', $sort['field']));
                }
            }
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

