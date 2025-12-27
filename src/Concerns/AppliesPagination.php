<?php

namespace BehindSolution\LaravelQueryGate\Concerns;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AppliesPagination
{
    /**
     * @param array{type: string, per_page: int|null, cursor: string|null} $pagination
     * @return LengthAwarePaginator|CursorPaginator|Collection<int, mixed>
     */
    public function apply(Builder $query, array $pagination)
    {
        switch ($pagination['type']) {
            case 'none':
                return $query->get();
            case 'cursor':
                return $query->cursorPaginate(
                    $pagination['per_page'],
                    ['*'],
                    'cursor',
                    $pagination['cursor']
                );
            case 'paginate':
            default:
                return $query->paginate(
                    $pagination['per_page'],
                    ['*'],
                    'page'
                );
        }
    }
}

