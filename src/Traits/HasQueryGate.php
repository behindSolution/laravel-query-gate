<?php

namespace BehindSolution\LaravelQueryGate\Traits;

use BehindSolution\LaravelQueryGate\Support\QueryGate;

trait HasQueryGate
{
    public static function queryGate(): QueryGate
    {
        return QueryGate::make();
    }
}



