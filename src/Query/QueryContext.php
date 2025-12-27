<?php

namespace BehindSolution\QueryGate\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class QueryContext
{
    public string $model;

    public Request $request;

    public Builder $query;

    public function __construct(string $model, Request $request, Builder $query)
    {
        $this->model = $model;
        $this->request = $request;
        $this->query = $query;
    }
}

