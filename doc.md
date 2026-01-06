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
            ->alias('users')
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
- Call `->sorts([...])` to whitelist which columns can be used for sorting (matching the syntax accepted by the `sort` query parameter).
- Call `->alias('users')` to expose pretty REST-like routes (e.g. `/query/users`, `/query/users/{id}`) in addition to the canonical query-string endpoint.

### Using the HasQueryGate trait

When you prefer to keep `config/query-gate.php` tidy, you can move the definition closer to the model by adding the `HasQueryGate` trait. Registering the class name alone tells Query Gate to call `Model::queryGate()` automatically:

```php
namespace App\Models;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Traits\HasQueryGate;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use HasQueryGate;

    public static function queryGate(): QueryGate
    {
        return QueryGate::make()
            ->alias('users')
            ->filters([
                'created_at' => 'date',
            ])
            ->select(['name', 'email']);
    }
}
```

With the trait in place, the configuration can simply list `User::class`:

```php
'models' => [
    App\Models\User::class,
];
```

### Versioning your definitions

Use `->version($identifier, $callback)` to keep backwards compatibility while iterating on filters, operators, or projected columns. Each version snapshot is independent; Query Gate automatically serves the latest one unless the client explicitly selects another via the `X-Query-Version` header (or legacy `version` query parameter). A changelog is always generated and available at `GET /query/{alias}/__changelog`.

```php
QueryGate::make()
    ->alias('users')
    ->version('2024-01-01', function (QueryGate $gate) {
        $gate->filters([
            'name' => 'string',
            'email' => 'email',
        ])->allowedFilters([
            'name' => ['like'],
            'email' => ['eq'],
        ])->select(['id', 'name', 'email']);
    })
    ->version('2024-11-01', function (QueryGate $gate) {
        $gate->filters([
            'name' => 'string',
            'email' => 'email',
            'created_at' => 'date',
        ])->allowedFilters([
            'name' => ['like'],
            'email' => ['eq', 'like'],
            'created_at' => ['gte', 'lte', 'between'],
        ])->select(['id', 'name', 'email', 'created_at']);
    });
```

Clients can opt into a specific snapshot:

```
GET /query/users
X-Query-Version: 2024-01-01
```

If the header is missing, Query Gate falls back to the latest version (`2024-11-01` in the example above). The changelog endpoint returns a chronological diff so consumers know what changed:

```json
{
  "model": "App\\Models\\User",
  "alias": "users",
  "default": "2024-11-01",
  "active": "2024-11-01",
  "versions": [
    {
      "version": "2024-01-01",
      "changes": []
    },
    {
      "version": "2024-11-01",
      "changes": [
        "Added filter: created_at",
        "Added operator: email.like",
        "Added select: created_at"
      ]
    }
  ]
}
```

### OpenAPI

Query Gate can export an OpenAPI document representing every configured model. Adjust the `openAPI` section in `config/query-gate.php` to control metadata, output path, server list, and authentication:

```php
'openAPI' => [
    'enabled' => true,
    'title' => 'Query Gate API',
    'description' => 'Generated documentation for Query Gate endpoints.',
    'version' => '1.2.0',
    'route' => '/docs/query-gate', // optional UI route handled by the host
    'json_route' => '/docs/query-gate.json',
    'ui' => 'redoc', // or 'swagger-ui'
    'ui_options' => [
        'hideDownloadButton' => true,
    ],
    'servers' => [
        ['url' => 'https://api.example.com', 'description' => 'Production'],
    ],
    'output' => [
        'format' => 'json',
        'path' => storage_path('app/query-gate-openapi.json'),
    ],
    'auth' => [
        'type' => 'http',
        'scheme' => 'bearer',
        'bearer_format' => 'JWT',
    ],
    'modifiers' => [
        \App\Docs\QueryGateModifier::class,
    ],
],
```

Generate (or refresh) the spec at any time with:

```bash
php artisan qg:openapi
```

Use `--output` (absolute path) and `--format=yaml` if you prefer custom destinations or YAML. The generator reads every `QueryGate::make()` declaration and documents filters, allowed operators, pagination mode, select clauses, actions, policies, cache TTLs, and alias information directly in the OpenAPI schema.

When `openAPI.enabled` is `true`, Query Gate also registers two routes:

- `GET /query/docs.json` (configurable via `openAPI.json_route`) returns the generated document on demand.
- `GET /query/docs` (configurable via `openAPI.route`) serves a documentation UI. The default renderer is [ReDoc](https://redoc.ly/), but you can switch to Swagger UI by setting `openAPI.ui = 'swagger-ui'`. Any UI-specific tweaks (e.g., hiding download buttons) can be provided through `openAPI.ui_options`. Apply custom middleware with `openAPI.middleware` when the docs should be protected. The Blade view embeds the full payload directly (no extra JSON request), which makes response caching straightforward.

Each entity registered in `query-gate.models` produces its own set of operations (`GET /query/{alias}`, `POST /query/{alias}`, `PATCH /query/{alias}/{id}`, `DELETE /query/{alias}/{id}`) with tailored summaries and tags. In the OpenAPI UI each model therefore appears as its own section (List Users, Create Users, Update Users, etc.), which keeps navigation and customization intuitive.

If you set an alias with `->alias('users')`, Query Gate publishes routes like `/query/users` in addition to the FQCN-based query-string endpoint. If no alias is defined, only the canonical `GET /query?model=App\\Models\\User` (and corresponding `POST/PATCH/DELETE`) is available.

### Extending the OpenAPI document

Sometimes you need to document endpoints that sit outside Query Gate (e.g., a dedicated controller for duplicating a user). Use `openAPI.modifiers` to register callables or classes that receive the generated document array and return a modified version:

```php
use BehindSolution\LaravelQueryGate\Contracts\OpenApi\DocumentModifier;

class QueryGateModifier implements DocumentModifier
{
    public function modify(array $document): array
    {
        $document['paths']['/users/duplicate'] = [
            'post' => [
                'summary' => 'Duplicate user',
                'tags' => ['Users'],
                'requestBody' => ['...'],
                'responses' => [
                    '201' => ['description' => 'User duplicated.'],
                ],
            ],
        ];

        return $document;
    }
}
```

You can also provide closures directly in the config if the logic is simple. Modifiers run in order, making it easy to compose multiple layers (base metadata, project-specific additions, per-environment tweaks, etc.).

## Making Requests

All requests are handled by the registered route (default `GET /query`). Provide either the fully-qualified model name or one of the configured aliases:

```
GET /query?model=App\Models\User
GET /query?model=users
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
| `not_in` | NOT IN   | `filter[id][not_in]=4,5,6`              |
| `between`| BETWEEN  | `filter[created_at][between]=2024-01-01,2024-01-31` |

### Sorting

```
sort=created_at:desc,id:asc
```

Ordering is applied exactly as provided and does not assume a primary key.
Call `->sorts(['created_at', 'id'])` on the builder to whitelist the fields clients may sort by; any attempt to sort using a field outside that list will result in HTTP 422.

### Pagination

You can choose between three pagination modes (`classic`, `cursor`, or `none`) by configuring the builder:

```php
QueryGate::make()->paginationMode('cursor'); // classic is the default
```

When you disable pagination (`none`), the full collection is returned. In `cursor` mode you must pass the `cursor` token supplied by the previous response. In `classic` mode the package delegates to Laravel's `paginate()` with the configured `per_page` size (defaults defined in `config/query-gate.php` or overridden per-model).

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
php artisan test --display-deprecations --testdox
```

