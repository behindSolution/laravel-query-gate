<?php

namespace BehindSolution\LaravelQueryGate\Concerns;

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
            $value = $definition['value'] ?? null;
            $callback = $definition['callback'] ?? null;

            if (!$field || !$operator) {
                continue;
            }

            if (is_callable($callback)) {
                $this->applyRawFilter($query, (string) $field, (string) $operator, $value, $callback);
                continue;
            }

            $this->applyFilter($query, (string) $field, (string) $operator, $value);
        }

        return $query;
    }

    /**
     * @param mixed $value
     */
    protected function applyFilter(Builder $query, string $field, string $operator, $value): void
    {
        if (str_contains($field, '.')) {
            $parts = explode('.', $field);
            $column = array_pop($parts);
            $relationPath = implode('.', $parts);

            if ($relationPath !== '' && $column !== '') {
                $query->whereHas($relationPath, function (Builder $relationQuery) use ($column, $operator, $value) {
                    $this->applyOperator($relationQuery, $column, $operator, $value);
                });

                return;
            }
        }

        $this->applyOperator($query, $field, $operator, $value);
    }

    /**
     * @param mixed $value
     */
    protected function applyRawFilter(Builder $query, string $field, string $operator, $value, callable $callback): void
    {
        if (str_contains($field, '.')) {
            $segments = explode('.', $field);
            $column = array_pop($segments);
            $relationPath = implode('.', $segments);

            if ($relationPath === '' || $column === null || $column === '') {
                return;
            }

            $query->whereHas($relationPath, function (Builder $relationQuery) use ($callback, $operator, $value, $column) {
                $callback($relationQuery, $operator, $value, $column);
            });

            return;
        }

        $callback($query, $operator, $value, $field);
    }

    /**
     * @param mixed $value
     */
    protected function applyOperator(Builder $query, string $field, string $operator, $value): void
    {
        $qualifiedField = str_contains($field, '.')
            ? $field
            : $query->qualifyColumn($field);

        switch ($operator) {
            case 'eq':
                $value === null
                    ? $query->whereNull($qualifiedField)
                    : $query->where($qualifiedField, '=', $value);
                break;
            case 'neq':
                $value === null
                    ? $query->whereNotNull($qualifiedField)
                    : $query->where($qualifiedField, '!=', $value);
                break;
            case 'lt':
                $query->where($qualifiedField, '<', $value);
                break;
            case 'lte':
                $query->where($qualifiedField, '<=', $value);
                break;
            case 'gt':
                $query->where($qualifiedField, '>', $value);
                break;
            case 'gte':
                $query->where($qualifiedField, '>=', $value);
                break;
            case 'like':
                if ($value !== null) {
                    $query->where($qualifiedField, 'like', $this->buildLikePattern((string) $value));
                }
                break;
            case 'in':
                $values = is_array($value) ? array_values(array_filter($value, static fn ($item) => $item !== null)) : [];
                if (!empty($values)) {
                    $query->whereIn($qualifiedField, $values);
                }
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($qualifiedField, $value);
                }
                break;
        }
    }

    protected function buildLikePattern(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (strpbrk($value, '%_') === false) {
            return '%' . addcslashes($value, '%_') . '%';
        }

        return $value;
    }
}

