# Query Gate

Query Gate offers a single HTTP entrypoint that turns incoming query parameters into Eloquent queries. It delegates business rules to the host application through closures and middleware so you can expose data in a controlled, explicit, and testable way.

## Requirements

- PHP 8.2+
- Laravel 9, 10, 11, or 12

## Installation

```bash
composer require behind-solution/laravel-query-gate
```

Publish the configuration file when you need to customize the route prefix, middleware, pagination defaults, or exposed models:

```bash
php artisan vendor:publish --tag=query-gate-config
```

The service provider is auto-discovered, so no manual registration is required.

## Configuration

Update `config/query-gate.php` to declare which models are available and how they should be scoped:

```php
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
        App\Models\User::class => [
            'query' => fn ($query, $request) => $query->where('active', true),
            'middleware' => ['auth:sanctum'],
            'actions' => [
                'update' => [
                    'validation' => [
                        'name' => ['sometimes', 'string'],
                    ],
                ],
                'delete' => [],
            ],
        ],
    ],
];
```

Each model entry can:

- Provide a `query` closure that receives the Eloquent builder and the current `Request`.
- Declare additional `middleware` that will run before the query is executed.

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

You can choose between classic pagination, cursor pagination, or no pagination:

```
pagination=paginate   # default
pagination=cursor
pagination=none
per_page=50
cursor=opaque-cursor-string
```

When `pagination=none`, the response returns the full collection. For `cursor`, you must pass the cursor token returned by the previous response.

## Actions (Create/Update/Delete)

Models can optionally expose mutable operations by defining them under the `actions` key in the configuration. Each action (`create`, `update`, `delete`) accepts an array with any of the following optional keys:

- `validation`: validation rules applied through Laravel's validator.
- `authorize`: closure that receives the current `Request` and the resolved model (for `create` it is a new instance). Return `false` to abort with HTTP 403.
- `handle`: closure that receives `($request, $model, $payload)` so you can fully control the persistence workflow.

If you omit a key, Query Gate uses a sensible default. Leaving the action array empty (`'update' => []`) enables the endpoint with default behavior.

```php
'actions' => [
    'create' => [
        'validation' => [
            'title' => ['required', 'string'],
            'body' => ['required', 'string'],
        ],
        'handle' => function ($request, $model, $payload) {
            $model->fill($payload);
            $model->save();

            $model->tags()->sync($request->input('tags', []));

            return $model->load('tags');
        },
    ],
    'update' => [],
    'delete' => [
        'authorize' => fn ($request, $model) => $request->user()->can('delete', $model),
    ],
],
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

Consumers can lock to major versions through Composer constraints:

```bash
composer require behind-solution/laravel-query-gate:^1.0
```

## Testing

Query Gate relies on Laravel's pagination and query builder. You can write integration tests in the host application by hitting the registered route and asserting the JSON structure or pagination metadata returned for your configured models.

