<?php

namespace BehindSolution\LaravelQueryGate\Support;

class SortParser
{
    /**
     * @return array<int, array{field: string, direction: string}>
     */
    public function parse(?string $input): array
    {
        if ($input === null || trim($input) === '') {
            return [];
        }

        $segments = array_filter(array_map('trim', explode(',', $input)));
        $result = [];

        foreach ($segments as $segment) {
            [$field, $direction] = $this->extractFieldAndDirection($segment);

            if ($field === null) {
                continue;
            }

            $result[] = [
                'field' => $field,
                'direction' => $direction,
            ];
        }

        return $result;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    protected function extractFieldAndDirection(string $segment): array
    {
        $parts = array_map('trim', explode(':', $segment, 2));
        $field = $parts[0] ?? null;
        $direction = strtolower($parts[1] ?? 'asc');

        if ($field === null || $field === '') {
            return [null, 'asc'];
        }

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        return [$field, $direction];
    }
}

