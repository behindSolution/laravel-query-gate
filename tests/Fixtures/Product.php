<?php

namespace BehindSolution\LaravelQueryGate\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasUuids;

    protected $table = 'products';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'testbench';

    protected $keyType = 'string';

    public $incrementing = false;
}
