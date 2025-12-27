<?php

namespace BehindSolution\LaravelQueryGate\Support;

use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
     * @param array<string, string|array<int, string>> $definitions
     * @return array<int, array{field: string, operator: string, value: mixed}>
     */
    public function parse(array $filters, array $definitions = []): array
    {
        $parsed = [];

        foreach ($filters as $field => $operators) {
            if (!is_string($field) || !is_array($operators)) {
                continue;
            }

            $rules = $this->resolveRulesForField($field, $definitions);

            foreach ($operators as $operator => $value) {
                if (!is_string($operator)) {
                    continue;
                }

                $operator = strtolower($operator);

                if (!in_array($operator, self::SUPPORTED_OPERATORS, true)) {
                    continue;
                }

                $normalizedValue = $this->normalizeValue($value, $operator, $field);

                if ($rules !== null) {
                    $this->validateValue($field, $operator, $normalizedValue, $rules);
                } elseif ($definitions !== []) {
                    throw new HttpException(422, sprintf('Filtering by "%s" is not allowed.', $field));
                }

                $parsed[] = [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $normalizedValue,
                ];
            }
        }

        return $parsed;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function normalizeValue($value, string $operator, string $field)
    {
        if ($operator === 'in') {
            $normalized = $this->normalizeArrayValue($value);

            if ($normalized === []) {
                throw new HttpException(422, sprintf('The filter "%s" must receive at least one value.', $field));
            }

            return $normalized;
        }

        if ($operator === 'between') {
            $values = $this->normalizeArrayValue($value);

            if (count($values) !== 2) {
                throw new HttpException(422, sprintf('The filter "%s" must receive exactly two values when using the between operator.', $field));
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

    /**
     * @param array<string, string|array<int, string>> $definitions
     * @return array<int, string>|null
     */
    protected function resolveRulesForField(string $field, array $definitions): ?array
    {
        if ($definitions === []) {
            return null;
        }

        if (!array_key_exists($field, $definitions)) {
            return null;
        }

        $rules = $definitions[$field];

        if (is_string($rules)) {
            return [$rules];
        }

        if (is_array($rules)) {
            return array_values(array_filter($rules, static function ($rule) {
                return is_string($rule) && $rule !== '';
            }));
        }

        return [];
    }

    /**
     * @param array<int, string> $rules
     * @param mixed $value
     */
    protected function validateValue(string $field, string $operator, $value, array $rules): void
    {
        if ($rules === []) {
            return;
        }

        $data = ['value' => $value];
        $validationRules = [];

        if (in_array($operator, ['in', 'between'], true)) {
            $arrayRules = ['array'];

            if ($operator === 'between') {
                $arrayRules[] = 'size:2';
            }

            $validationRules['value'] = $arrayRules;
            $validationRules['value.*'] = $rules;
        } else {
            $validationRules['value'] = $rules;
        }

        $validator = Validator::make($data, $validationRules);

        if ($validator->fails()) {
            throw new HttpException(422, $validator->errors()->first());
        }
    }
}

