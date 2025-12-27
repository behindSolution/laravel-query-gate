<?php

namespace BehindSolution\QueryGate\Concerns;

use Illuminate\Database\Eloquent\Builder;

class AppliesFilters
{
    /**
     * @param array<int, array{field: string, operator: string, value: mixed}> $filters
     */
    public function apply(Builder $query, array $filters): Builder
    {
        foreach ($filters as $definition) {
            $field = $definition['field'] ?? null;
            $operator = $definition['operator'] ?? null;

            if (!$field || !$operator) {
                continue;
            }

            $value = $definition['value'] ?? null;

            $this->applyFilter($query, $field, $operator, $value);
        }

        return $query;
    }

    /**
     * @param mixed $value
     */
    protected function applyFilter(Builder $query, string $field, string $operator, $value): void
    {
        switch ($operator) {
            case 'eq':
                $value === null
                    ? $query->whereNull($field)
                    : $query->where($field, '=', $value);
                break;
            case 'neq':
                $value === null
                    ? $query->whereNotNull($field)
                    : $query->where($field, '!=', $value);
                break;
            case 'lt':
                $query->where($field, '<', $value);
                break;
            case 'lte':
                $query->where($field, '<=', $value);
                break;
            case 'gt':
                $query->where($field, '>', $value);
                break;
            case 'gte':
                $query->where($field, '>=', $value);
                break;
            case 'like':
                if ($value !== null) {
                    $query->where($field, 'like', $value);
                }
                break;
            case 'in':
                $values = is_array($value) ? $value : [];
                if (!empty($values)) {
                    $query->whereIn($field, $values);
                }
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($field, $value);
                }
                break;
        }
    }
}

