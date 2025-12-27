<?php

namespace BehindSolution\LaravelQueryGate\Support;

class FilterParser
{
    public const SUPPORTED_OPERATORS = [
        'eq',
        'neq',
        'lt',
        'lte',
        'gt',
        'gte',
        'like',
        'in',
        'between',
    ];

    /**
     * @param array<string, array<string, mixed>> $filters
     * @return array<int, array{field: string, operator: string, value: mixed}>
     */
    public function parse(array $filters): array
    {
        $parsed = [];

        foreach ($filters as $field => $definitions) {
            if (!is_string($field) || !is_array($definitions)) {
                continue;
            }

            foreach ($definitions as $operator => $value) {
                if (!is_string($operator)) {
                    continue;
                }

                $operator = strtolower($operator);

                if (!in_array($operator, self::SUPPORTED_OPERATORS, true)) {
                    continue;
                }

                $parsed[] = [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $this->normalizeValue($value, $operator),
                ];
            }
        }

        return $parsed;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function normalizeValue($value, string $operator)
    {
        if ($operator === 'in') {
            return $this->normalizeArrayValue($value);
        }

        if ($operator === 'between') {
            $values = $this->normalizeArrayValue($value);

            if (count($values) !== 2) {
                return null;
            }

            return $values;
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return array<int, mixed>
     */
    protected function normalizeArrayValue($value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (is_string($value)) {
            $parts = array_filter(array_map('trim', explode(',', $value)), static function ($part) {
                return $part !== '';
            });

            return array_values($parts);
        }

        return [];
    }
}

