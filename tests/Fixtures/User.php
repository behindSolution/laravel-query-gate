<?php

namespace BehindSolution\LaravelQueryGate\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'testbench';

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}


