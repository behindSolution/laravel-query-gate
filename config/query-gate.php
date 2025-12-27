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

