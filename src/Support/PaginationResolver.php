<?php

namespace BehindSolution\LaravelQueryGate\Support;

class PaginationResolver
{
    protected int $defaultPerPage;

    protected int $maxPerPage;

    public function __construct(int $defaultPerPage = 15, int $maxPerPage = 100)
    {
        $this->defaultPerPage = max(1, $defaultPerPage);
        $this->maxPerPage = max($this->defaultPerPage, $maxPerPage);
    }

    /**
     * @return array{type: string, per_page: int|null, cursor: string|null}
     */
    public function resolve(?string $mode, ?int $perPage, ?string $cursor): array
    {
        $type = $this->normalizeMode($mode);
        $size = $type === 'none' ? null : $this->normalizePerPage($perPage);

        return [
            'type' => $type,
            'per_page' => $size,
            'cursor' => $type === 'cursor' ? $cursor : null,
        ];
    }

    protected function normalizeMode(?string $mode): string
    {
        $mode = $mode ? strtolower($mode) : null;

        if (in_array($mode, ['classic', 'cursor', 'none'], true)) {
            return $mode;
        }

        return 'classic';
    }

    protected function normalizePerPage(?int $perPage): int
    {
        if ($perPage === null) {
            return $this->defaultPerPage;
        }

        $perPage = max(1, $perPage);

        return min($perPage, $this->maxPerPage);
    }
}

