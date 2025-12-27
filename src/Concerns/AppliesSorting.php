<?php

namespace BehindSolution\LaravelQueryGate\Concerns;

use Illuminate\Database\Eloquent\Builder;

class AppliesSorting
{
    /**
     * @param array<int, array{field: string, direction: string}> $sorts
     */
    public function apply(Builder $query, array $sorts): Builder
    {
        foreach ($sorts as $sort) {
            $field = $sort['field'] ?? null;
            $direction = $sort['direction'] ?? 'asc';

            if (!$field) {
                continue;
            }

            $query->orderBy($field, $direction === 'desc' ? 'desc' : 'asc');
        }

        return $query;
    }
}

