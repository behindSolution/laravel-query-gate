<?php

namespace BehindSolution\LaravelQueryGate\Tests\Fixtures;

use Illuminate\Auth\Access\HandlesAuthorization;

class PostPolicy
{
    use HandlesAuthorization;

    public function create($user): bool
    {
        return $user !== null;
    }

    public function createRestricted($user): bool
    {
        return false;
    }
}


