<?php

namespace BehindSolution\LaravelQueryGate\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    protected $table = 'comments';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'testbench';

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}


