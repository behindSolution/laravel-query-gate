<?php

namespace BehindSolution\LaravelQueryGate\Tests\Fixtures;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Traits\HasQueryGate;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasQueryGate;

    protected $table = 'articles';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'testbench';

    public static function queryGate(): QueryGate
    {
        return QueryGate::make()
            ->alias('articles')
            ->filters([
                'title' => ['string', 'max:255'],
            ])
            ->select(['title']);
    }
}



