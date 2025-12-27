# Query Gate

Query Gate offers a single HTTP entrypoint that turns incoming query parameters into Eloquent queries. It delegates business rules to the host application through closures and middleware so you can expose data in a controlled, explicit, and testable way.

## Requirements

- PHP 8.2+
- Laravel 9, 10, 11, or 12

## Installation

```bash
composer require behindsolution/laravel-query-gate
```

Publish the configuration file when you need to customize the route prefix, middleware, pagination defaults, or exposed models:

```bash
php artisan vendor:publish --tag=query-gate-config
```

The service provider is auto-discovered, so no manual registration is required.

## Configuration

Update `config/query-gate.php` to declare which models are available and how they should be scoped:

```php
use BehindSolution\LaravelQueryGate\Support\QueryGate;

return [
    'route' => [
        'prefix' => 'query',
        'middleware' => ['throttle:60,1'],
    ],

    'pagination' => [
        'per_page' => 25,
        'max_per_page' => 200,
    ],

    'models' => [
        App\Models\User::class => QueryGate::make()
            ->cache(60, 'users-index')
            ->filters([
                'created_at' => 'date',
                'posts.title' => ['string', 'max:255'],
            ])
            ->allowedFilters([
                'created_at' => ['eq', 'between'],
                'posts.title' => ['like'],
            ])
            ->rawFilters([
                'posts.title' => fn ($builder, $operator, $value, $column) => $builder->where($column, 'like', '%' . $value . '%'),
            ])
            ->select(['created_at', 'posts.title'])
            ->query(fn ($query, $request) => $query->where('active', true))
            ->middleware(['auth:sanctum'])
            ->paginationMode('cursor')
            ->actions(fn ($actions) => $actions
                ->update(fn ($action) => $action->validations([
                    'name' => ['sometimes', 'string'],
                ]))
                ->delete()
            ),
    ],
];
```

Each model entry can:

- Provide a `query` closure that receives the Eloquent builder and the current `Request`.
- Declare additional `middleware` that will run before the query is executed.
- Call `->filters([...])` to whitelist which fields can be filtered and which validation rules each one must satisfy. Relation filters use dot notation (`'posts.title'`).
- Call `->allowedFilters([...])` to restrict which operators are accepted per field (for example `['created_at' => ['eq', 'between']]`).
- Call `->rawFilters([...])` to override how specific filters are applied while still benefiting from the validation/safe-list provided by `->filters`.
- Call `->select([...])` to restrict the attributes retrieved from the database (including relation columns via dot notation).

## Making Requests

All requests are handled by the registered route (default `GET /query`). You must provide the fully-qualified model name:

```
GET /query?model=App\Models\User
```

### Filters

Filters follow an Azure/OData-inspired structure: `filter[field][operator]=value`.

| Operator | Meaning  | Example                                 |
|----------|----------|-----------------------------------------|
| `eq`     | equals   | `filter[status][eq]=active`             |
| `neq`    | not eq   | `filter[status][neq]=inactive`          |
| `lt`     | <        | `filter[created_at][lt]=2024-01-01`     |
| `lte`    | <=       | `filter[created_at][lte]=2024-01-01`    |
| `gt`     | >        | `filter[created_at][gt]=2024-01-01`     |
| `gte`    | >=       | `filter[created_at][gte]=2024-01-01`    |
| `like`   | LIKE     | `filter[email][like]=%example.com`      |
| `in`     | IN       | `filter[id][in]=1,2,3`                  |
| `between`| BETWEEN  | `filter[created_at][between]=2024-01-01,2024-01-31` |

### Sorting

```
sort=created_at:desc,id:asc
```

Ordering is applied exactly as provided and does not assume a primary key.

### Pagination

You can choose between classic pagination (default), cursor pagination, or no pagination by configuring the builder:

```php
QueryGate::make()->paginationMode('cursor'); // or 'none'
```

When you disable pagination (`none`), the full collection is returned. For cursor pagination the next-page token provided in the response should be echoed back via the `cursor` query parameter. The page size follows the host configuration (`config('query-gate.pagination.per_page')` and any per-model overrides).

### Caching

Call `->cache(60, 'users-index')` on the builder to cache list responses for 60 seconds. The optional name lets the host invalidate the cache manually; when omitted the model class name is used. Query Gate hashes the current model, filters, sorts, pagination parameters, and authenticated user identifier when building the cache key, and automatically clears the cache after `create`, `update`, or `delete` actions.

### Filters

Define allowed filters with `->filters()` and validate incoming values using any Laravel validation rule. Requests attempting to filter by a field that was not declared will be rejected with HTTP 422. Relation filters leverage dot notation and support the entire operator set:

```
filter[posts.title][eq]=News
filter[created_at][between]=2024-01-01,2024-01-31
```

When you need to take over the actual query logic, pair the whitelist with `->rawFilters()`. The callback receives the current builder (already scoped to the relation when the filter path contains dots), the operator, the raw value, and the resolved column so you can perform joins or specialised comparisons:

```php
->filters(['posts.comments.name' => 'string'])
->allowedFilters(['posts.comments.name' => ['like']])
->rawFilters([
    'posts.comments.name' => fn ($builder, $operator, $value, $column) =>
        $builder->where($column, 'like', '%' . $value . '%'),
]);
```

### Selecting Columns

Use `->select(['created_at', 'posts.title'])` to limit which attributes are selected and serialized. Query Gate automatically keeps primary and foreign keys required to hydrate relations. Relation selections currently support a single relation depth (e.g. `posts.title`).

## Actions (Create/Update/Delete)

Models can optionally expose mutable operations by chaining `->actions()` on the builder. Inside the callback you receive an `ActionsBuilder` instance where each action (`create`, `update`, `delete`) can be customised:

- `->validations([...])` applies validation rules before handling the payload.
- `->policy('ability')` or `->policy(['ability', 'another'])` runs Laravel's policy pipeline (via `Gate::authorize`) for the resolved model.
- `->authorize(fn ($request, $model) => ...)` keeps the lower-level hook when you need custom logic.
- `->handle(fn ($request, $model, $payload) => ...)` replaces the default persistence workflow.

Omitting the callback keeps the default behaviour for that action.

```php
->actions(fn ($actions) => $actions
    ->create(fn ($action) => $action
        ->validations([
            'title' => ['required', 'string'],
            'body' => ['required', 'string'],
        ])
        ->handle(function ($request, $model, $payload) {
            $model->fill($payload);
            $model->save();

            $model->tags()->sync($request->input('tags', []));

            return $model->load('tags');
        })
    )
    ->update()
    ->delete(fn ($action) => $action->policy('delete'))
)
```

### Endpoints

All endpoints require the `model` query parameter:

- `GET /query?model=App\Models\Post` → list with filters/sort/pagination.
- `POST /query?model=App\Models\Post` → create (requires `actions.create` declaration).
- `PATCH /query/{id}?model=App\Models\Post` → update by route key (requires `actions.update`).
- `DELETE /query/{id}?model=App\Models\Post` → delete by route key (requires `actions.delete`).

When `validation` rules exist, the validated payload is passed to the default handler (and to custom `handle` closures). Without rules, the raw request data is used.

If you leave `actions` undefined or omit a specific action, the corresponding endpoint responds with HTTP 405.

## Middleware Pipeline

Query Gate resolves the configured model, then runs any middleware defined in `config/query-gate.php` for that model before executing the query. Use this to enforce authentication, tenancy, throttling, or custom guards per dataset.

## Testing

Query Gate relies on Laravel's pagination and query builder. You can write integration tests in the host application by hitting the registered route and asserting the JSON structure or pagination metadata returned for your configured models.

Run the package test suite locally with:

```bash
vendor/bin/phpunit --display-deprecations --testdox
```

