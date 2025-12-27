<?php

namespace BehindSolution\LaravelQueryGate\Query;

use BehindSolution\LaravelQueryGate\Traits\HasFilters;
use BehindSolution\LaravelQueryGate\Traits\HasPagination;
use BehindSolution\LaravelQueryGate\Traits\HasSorting;
use Closure;
use Illuminate\Database\Eloquent\Builder;

class QueryExecutor
{
    use HasFilters;
    use HasSorting;
    use HasPagination;

    /**
     * @param array{query?: Closure|null} $configuration
     * @return mixed
     */
    public function execute(QueryContext $context, array $configuration = [])
    {
        $this->applyBaseQuery(
            $context,
            $configuration['query'] ?? null
        );

        $this->applyFilters($context);
        $this->applySorting($context);

        return $this->applyPagination($context);
    }

    protected function applyBaseQuery(QueryContext $context, ?Closure $callback): void
    {
        if ($callback === null) {
            return;
        }

        $result = $callback($context->query, $context->request);

        if ($result instanceof Builder) {
            $context->query = $result;
        }
    }
}

