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

    'openAPI' => [
        'enabled' => false,
        'title' => 'Query Gate API',
        'description' => 'Generated documentation for Query Gate endpoints.',
        'version' => '1.0.0',
        'route' => 'query/docs',
        'json_route' => null,
        'ui' => 'redoc',
        'ui_options' => [],
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
        'middleware' => [],
        'modifiers' => [],
    ],

    'models' => [
        // App\Models\User::class => QueryGate::make()
        //      ->alias('users')
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
//     ->sorts(['created_at'])
        //     ->query(fn ($query, $request) => $query->where('active', true))
        //     ->middleware(['auth:sanctum'])
        //     ->paginationMode('cursor')
        //     ->actions(fn ($actions) => $actions
        //         ->update(fn ($action) => $action->validations(['name' => ['sometimes', 'string']]))
        //         ->delete()
        //     ),
    ],

];

