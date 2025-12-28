<?php

use BehindSolution\LaravelQueryGate\Support\QueryGate;

return [

    'route' => [
        'prefix' => 'query',
        'middleware' => [],
    ],

    'pagination' => [
        'per_page' => 15,
        'max_per_page' => 100,
    ],

    'swagger' => [
        'enabled' => false,
        'title' => 'Query Gate API',
        'description' => 'Generated documentation for Query Gate endpoints.',
        'version' => '1.0.0',
        'route' => null,
        'servers' => [],
        'output' => [
            'format' => 'json',
            'path' => storage_path('app/query-gate-openapi.json'),
        ],
        'auth' => [
            'type' => null,
            'name' => null,
            'in' => 'header',
            'scheme' => null,
            'bearer_format' => null,
            'flows' => [],
        ],
        'tags' => [],
    ],

    'model_aliases' => [
        'users' => App\Models\User::class,
        'posts' => App\Models\Post::class,
    ],

    'models' => [
        // App\Models\User::class => QueryGate::make()
        //     ->cache(60)
        //     ->filters([
        //         'created_at' => 'date',
        //         'posts.title' => ['string', 'max:255'],
        //     ])
        //     ->allowedFilters([
        //         'created_at' => ['eq', 'between'],
        //         'posts.title' => ['like'],
        //     ])
        //     ->rawFilters([
        //         'posts.title' => fn ($builder, $operator, $value, $column) => $builder->where($column, 'like', '%' . $value . '%'),
        //     ])
        //     ->select(['created_at', 'posts.title'])
        //     ->query(fn ($query, $request) => $query->where('active', true))
        //     ->middleware(['auth:sanctum'])
        //     ->paginationMode('cursor')
        //     ->actions(fn ($actions) => $actions
        //         ->update(fn ($action) => $action->validations(['name' => ['sometimes', 'string']]))
        //         ->delete()
        //     ),
    ],

];

