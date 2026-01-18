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
- Call `->withoutListing()` to disable the GET listing endpoint while keeping actions (create, update, delete) available.

### Disabling listing

Sometimes you want to expose a model only for mutations (create, update, delete) without allowing clients to list records. Use `->withoutListing()` to disable the GET endpoint:

```php
QueryGate::make()
    ->alias('users')
    ->withoutListing()
    ->actions(fn ($actions) => $actions
        ->create(fn ($action) => $action
            ->validations([
                'name' => ['required', 'string'],
                'email' => ['required', 'email', 'unique:users,email'],
            ])
        )
        ->update()
        ->delete()
    );
```

When listing is disabled, `GET /query/users` returns HTTP 403 with the message "Listing is not available for this resource." All configured actions remain fully operational.

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

### Custom Actions in OpenAPI

Custom actions registered with `->use(ActionClass::class)` are automatically documented in the OpenAPI spec. The generator **analyzes your code statically** to determine whether the action requires a model identifier (`{id}` in the URL).

#### Automatic Detection

The OpenAPI generator inspects the `handle()` method to check if it uses the `$model` parameter:

- **Uses `$model`**: `POST /query/posts/{id}/publish` - requires an identifier
- **Doesn't use `$model`**: `POST /query/posts/bulk-publish` - no identifier needed

```php
// This action USES $model → generates /query/posts/{id}/publish
class PublishPost extends AbstractQueryGateAction
{
    public function action(): string { return 'publish'; }

    public function handle($request, $model, array $payload)
    {
        $model->status = 'published';  // ← $model is used
        $model->save();
        return $model;
    }
}

// This action does NOT use $model → generates /query/posts/bulk-publish
class BulkPublishPosts extends AbstractQueryGateAction
{
    public function action(): string { return 'bulk-publish'; }

    public function handle($request, $model, array $payload)
    {
        // Only uses $request and $payload, not $model
        $ids = $payload['ids'] ?? [];
        Post::whereIn('id', $ids)->update(['status' => 'published']);
        return ['published_count' => count($ids)];
    }
}
```

#### Manual Override with `withoutQuery()`

You can also explicitly configure whether an action needs an identifier using `withoutQuery()`:

```php
->actions(fn ($actions) => $actions
    ->create(fn ($action) => $action
        ->validations(['ids' => 'required|array'])
        ->handle(fn ($request, $model, $payload) => /* ... */)
        ->withoutQuery()  // Forces no {id} in URL
    )
)
```

Each custom action in the OpenAPI spec includes:
- The HTTP method defined by `method()` in the action class
- Request body schema based on `validations()`
- Response status codes from `status()`
- Description mentioning the handler class name

### OpenAPI Request Examples

You can provide custom examples for action request bodies using the `openapiRequest()` method. This helps API consumers understand what data to send.

#### For Inline Actions

```php
QueryGate::make()
    ->alias('posts')
    ->actions(fn ($actions) => $actions
        ->create(fn ($action) => $action
            ->validations([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'string',
            ])
            ->openapiRequest([
                'title' => 'My New Post',
                'content' => 'This is the content of my post.',
                'status' => 'draft',
            ])
        )
    );
```

When `openapiRequest()` is defined, it **completely replaces** the inferred examples from validation rules. Only the fields you specify will appear in the example.

#### For Custom Action Classes

Override the `openapiRequest()` method in your action class:

```php
class PublishPostAction extends AbstractQueryGateAction
{
    public function action(): string
    {
        return 'publish';
    }

    public function method(): string
    {
        return 'POST';
    }

    public function validations(): array
    {
        return [
            'scheduled_at' => 'nullable|date',
            'notify_subscribers' => 'boolean',
        ];
    }

    public function handle($request, $model, array $payload)
    {
        // ... implementation
    }

    public function openapiRequest(): array
    {
        return [
            'scheduled_at' => '2024-06-01T10:00:00Z',
            'notify_subscribers' => true,
        ];
    }
}
```

This generates an OpenAPI request body example:

```json
{
    "scheduled_at": "2024-06-01T10:00:00Z",
    "notify_subscribers": true
}
```

### API Versioning in OpenAPI

When you define versions using `->version()`, the OpenAPI documentation includes:
- An `X-Query-Version` header parameter with all available versions
- The default version highlighted in the parameter description
- Version information in the model definition schema

```php
QueryGate::make()
    ->alias('posts')
    ->version('2024-01-01', fn ($gate) => $gate
        ->filters(['title' => 'string'])
        ->select(['id', 'title'])
    )
    ->version('2024-06-01', fn ($gate) => $gate
        ->filters(['title' => 'string', 'status' => 'string'])
        ->select(['id', 'title', 'status'])
    );
```

The OpenAPI spec will show:

```yaml
parameters:
  - name: X-Query-Version
    in: header
    required: false
    description: "API version to use. Available versions: 2024-01-01, 2024-06-01. Default: 2024-06-01."
    schema:
      type: string
      enum: ["2024-01-01", "2024-06-01"]
      default: "2024-06-01"
```

### Resource Fields in OpenAPI

When you use `->select(UserResource::class)`, the OpenAPI generator **analyzes the Resource's `toArray()` method** to extract field names and infer appropriate example values:

```php
// Your Resource
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at,
            'is_active' => $this->is_active,
        ];
    }
}

// Generated OpenAPI example:
{
    "data": [{
        "id": 1,
        "name": "string",
        "email": "user@example.com",
        "created_at": "2024-01-01T00:00:00Z",
        "is_active": true
    }]
}
```

The generator infers example values based on field naming patterns:

| Field Pattern | Example Value |
|---------------|---------------|
| `id` | `1` |
| `*_at`, `*date*` | `"2024-01-01T00:00:00Z"` |
| `*_count`, `*count*` | `0` |
| `is_*`, `has_*` | `true` |
| `*email*` | `"user@example.com"` |
| `*url*`, `*link*` | `"https://example.com"` |
| `*price*`, `*amount*` | `0.00` |
| Other | `"string"` |

### Filter Examples in OpenAPI

The OpenAPI generator provides intelligent examples for filter operators based on validation rules:

| Validation Rule | Operator | Example |
|-----------------|----------|---------|
| `date` | `between` | `2024-01-01,2024-12-31` |
| `date` | `gte`, `lte` | `2024-01-01` |
| `integer` | `between` | `1,100` |
| `integer` | `gt`, `lt` | `10` |
| `string` | `like` | `%search%` |
| `string` | `in` | `value1,value2,value3` |

This makes the interactive documentation (ReDoc/Swagger UI) more useful for API consumers testing endpoints.

### OpenAPI Response Examples

While the generator infers example values from field names, you can provide explicit response examples using the `->openapiResponse()` method. This is especially useful when:

- You want specific, meaningful values instead of generic ones
- You need to show real-world data patterns
- The field naming doesn't match the inference patterns

```php
use BehindSolution\LaravelQueryGate\Support\QueryGate;

QueryGate::make()
    ->alias('users')
    ->select(['id', 'name', 'email', 'status'])
    ->openapiResponse([
        'id' => 42,
        'name' => 'John Doe',
        'email' => 'john.doe@example.com',
        'status' => 'active',
    ]);
```

The custom examples **override** any inferred values, so you can mix both approaches:

```php
// Only override specific fields
->openapiResponse([
    'name' => 'Jane Smith',  // Override
    'status' => 'verified',   // Override
    // id, email will use inferred values
])
```

#### Dot Notation for Nested Relations

When your response includes nested relations (from `->select(['tags.id', 'tags.name'])`), use dot notation to set examples for nested fields:

```php
QueryGate::make()
    ->alias('posts')
    ->select(['id', 'title', 'tags.id', 'tags.name', 'author.name'])
    ->openapiResponse([
        'id' => 1,
        'title' => 'Getting Started with Laravel',
        'tags.id' => 10,
        'tags.name' => 'Technology',
        'author.name' => 'Jane Doe',
    ]);
```

This generates nested structures in the OpenAPI example:

```json
{
    "data": [{
        "id": 1,
        "title": "Getting Started with Laravel",
        "tags": [{
            "id": 10,
            "name": "Technology"
        }],
        "author": {
            "name": "Jane Doe"
        }
    }]
}
```

#### Combining with Resources

Custom examples work with Resource classes too:

```php
QueryGate::make()
    ->alias('users')
    ->select(UserResource::class)
    ->openapiResponse([
        'id' => 999,
        'full_name' => 'Administrator',
        'role' => 'super_admin',
    ]);
```

The examples override the values inferred from the Resource's `toArray()` method.

#### Per-Version Examples

Each version can have its own examples:

```php
QueryGate::make()
    ->alias('posts')
    ->version('2024-01-01', fn ($gate) => $gate
        ->select(['id', 'title'])
        ->openapiResponse([
            'id' => 1,
            'title' => 'Version 1 Post',
        ])
    )
    ->version('2024-06-01', fn ($gate) => $gate
        ->select(['id', 'title', 'status'])
        ->openapiResponse([
            'id' => 2,
            'title' => 'Version 2 Post',
            'status' => 'published',
        ])
    );
```

The OpenAPI documentation will use the latest version's examples by default.

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

When you disable pagination (`none`), the full collection is returned wrapped in a `data` key for consistency. In `cursor` mode you must pass the `cursor` token supplied by the previous response. In `classic` mode the package delegates to Laravel's `paginate()` with the configured `per_page` size (defaults defined in `config/query-gate.php` or overridden per-model).

#### Consistent Response Format

All pagination modes return a consistent response format with pagination metadata at the root level:

**Classic pagination:**
```json
{
    "data": [...],
    "current_page": 1,
    "first_page_url": "https://example.com/query/posts?page=1",
    "from": 1,
    "last_page": 5,
    "last_page_url": "https://example.com/query/posts?page=5",
    "links": [...],
    "next_page_url": "https://example.com/query/posts?page=2",
    "path": "https://example.com/query/posts",
    "per_page": 15,
    "prev_page_url": null,
    "to": 15,
    "total": 75
}
```

**Cursor pagination:**
```json
{
    "data": [...],
    "path": "https://example.com/query/posts",
    "per_page": 15,
    "next_cursor": "eyJpZCI6MTAsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0",
    "next_page_url": "https://example.com/query/posts?cursor=eyJ...",
    "prev_cursor": null,
    "prev_page_url": null
}
```

**No pagination (`none`):**
```json
{
    "data": [...]
}
```

This consistent format is maintained regardless of whether you use `->select()` with an array of columns or a Resource class.

#### Cursor Pagination and Primary Key

When using cursor pagination, Query Gate automatically includes the primary key in the ORDER BY clause as a tiebreaker. This ensures correct pagination even when multiple records share the same value for the sort column:

```
GET /query/posts?sort=created_at:desc
```

The cursor will include both the sort column and the primary key:
```json
{
    "created_at": "2024-01-15T10:00:00.000000Z",
    "id": 42,
    "_pointsToNextItems": true
}
```

This prevents issues with backward navigation when records have identical sort values.

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

#### Using API Resources

Instead of specifying individual columns, you can pass a Laravel `JsonResource` class to `select()`. This lets you leverage the full power of API Resources for transforming your data:

```php
use App\Http\Resources\UserResource;

QueryGate::make()
    ->alias('users')
    ->select(UserResource::class)
    ->actions(fn ($actions) => $actions
        ->create(fn ($a) => $a->validations(['name' => 'required']))
        ->update(fn ($a) => $a->validations(['name' => 'sometimes']))
    );
```

When a Resource class is configured:
- **List queries** return an `AnonymousResourceCollection` with pagination metadata preserved
- **Create/Update actions** return the Resource instance wrapping the model
- The Resource's `toArray()` method controls the output format

Example Resource:

```php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'member_since' => $this->created_at->diffForHumans(),
        ];
    }
}
```

You can switch between array-based and Resource-based selection at any time. The last call to `select()` wins:

```php
// Resource takes precedence
QueryGate::make()
    ->select(['id', 'name'])
    ->select(UserResource::class); // This is used

// Array takes precedence
QueryGate::make()
    ->select(UserResource::class)
    ->select(['id', 'name']); // This is used
```

## Actions

Models can optionally expose mutable operations by chaining `->actions()` on the builder. Inside the callback you receive an `ActionsBuilder` instance where each action (`create`, `update`, `delete`, `detail`) can be customised:

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
    ->detail()
)
```

#### Detail action

The `detail` action provides a dedicated endpoint for retrieving a single record by its identifier. Unlike the list endpoint, `detail` can expose more information about a specific record.

```php
QueryGate::make()
    ->alias('posts')
    ->select(['id', 'title'])  // List shows minimal fields
    ->actions(fn ($actions) => $actions
        ->detail()  // GET /query/posts/{id}
    );
```

**Simplified route:** When `detail` is configured, you can access it directly at `GET /query/posts/{id}` instead of the longer `GET /query/posts/{id}/detail`. This provides a cleaner REST-like API while maintaining backwards compatibility with the explicit route.

**Custom select and query for detail**

The `detail` action supports its own `->select()` and `->query()` methods, allowing you to return more data than the listing endpoint. If not specified, it falls back to the root configuration.

```php
QueryGate::make()
    ->alias('posts')
    ->select(['id', 'title'])  // List shows only id and title
    ->query(fn ($query) => $query->where('status', 'published'))  // List shows only published
    ->actions(fn ($actions) => $actions
        ->detail(fn ($action) => $action
            ->select(['id', 'title', 'content', 'status', 'author', 'created_at'])  // Detail shows more fields
            ->query(fn ($query) => $query->with(['author', 'tags']))  // Detail loads relations
        )
    );
```

You can also use a Resource class for the detail response:

```php
use App\Http\Resources\PostDetailResource;

QueryGate::make()
    ->alias('posts')
    ->select(['id', 'title'])  // List uses array
    ->actions(fn ($actions) => $actions
        ->detail(fn ($action) => $action
            ->select(PostDetailResource::class)  // Detail uses Resource
            ->policy('view')
        )
    );
```

The `detail` action:

- Uses HTTP method `GET` by default
- Requires an identifier (returns 404 if not found)
- Does not require validation rules (read-only operation)
- Does not invalidate cache (read-only operation)
- Supports `->policy()`, `->authorize()`, and `->handle()` like other actions

#### Returning Resources from handle()

Custom `handle()` callbacks can return a `JsonResource` instance for full control over the response format. This is useful when you need different transformations for different actions:

```php
use App\Http\Resources\UserCreateResource;
use App\Http\Resources\UserUpdateResource;

->actions(fn ($actions) => $actions
    ->create(fn ($action) => $action
        ->validations([
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
        ])
        ->handle(function ($request, $model, $payload) {
            $model->fill($payload);
            $model->save();

            // Return a Resource - it will be serialized automatically
            return new UserCreateResource($model);
        })
    )
    ->update(fn ($action) => $action
        ->validations(['name' => 'sometimes'])
        ->handle(function ($request, $model, $payload) {
            $model->fill($payload);
            $model->save();

            return new UserUpdateResource($model);
        })
    )
)
```

Inside the callback you receive an `ActionsBuilder` instance, so you can continue to call `->validations()`, `->policy()`, `->authorize()`, or `->handle()` exactly as before.

#### Class-based actions

When the logic no longer fits nicely inside a closure, extract it to a dedicated action class. Implement the `QueryGateAction` contract (or extend the helper `AbstractQueryGateAction`) and register it with `->use()`:

```php
use App\Actions\QueryGate\DoPayment;
use App\Actions\QueryGate\RefundPayment;

QueryGate::make()
    ->actions(fn ($actions) => $actions
        ->use(DoPayment::class)
        ->use(RefundPayment::class)
    );
```

An example action class:

```php
namespace App\Actions\QueryGate;

use BehindSolution\LaravelQueryGate\Actions\AbstractQueryGateAction;

class DoPayment extends AbstractQueryGateAction
{
    public function action(): string
    {
        return 'refund';
    }

    public function method(): string
    {
        return 'POST';
    }

    /**
     * @param array<string, mixed> $payload
     * @return mixed
     */
    public function handle($request, $model, array $payload)
    {
        $payment = app(ProcessPayment::class)($model, $payload);

        return [
            'payment_id' => $payment->id,
            'status' => $payment->status,
        ];
    }

    public function status(): ?int
    {
        return 202;
    }

    public function validations(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
        ];
    }

    public function authorize($request, $model): ?bool
    {
        return $request->user()?->can('pay', $model);
    }
}
```

- `handle()` is mandatory. Returning a `Response`, `Responsable`, or `JsonResource` short-circuits the serializer; any other value is wrapped in JSON when the client expects JSON. Use `status()` to override the default HTTP status (for example `202 Accepted`).
- `method()` lets you pick the HTTP verb required to trigger the action (defaults to `POST`). When an alias is configured, Query Gate exposes a route such as `/{alias}/{action}` that honours the declared verb (e.g. `POST /query/users/refund`). The canonical query-string endpoint (`POST /query?model=App\Models\User&action=refund`) remains available for non-aliased access.
- `validations()`, `authorize()`, and `policy()` remain optional hooks identical to the ones provided by `ActionsBuilder`.
- If `status()` returns `null`, the package falls back to the default status code (`200 OK`, or the specific value used by the built-in handlers).

Generate a new action class with:

```bash
php artisan qg:action DoPayment
```

The command creates `app/Actions/QueryGate/DoPayment.php` with all optional methods scaffolded so you can pick the ones you need.

### Endpoints

**Canonical endpoints** (using query parameter):

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/query?model=App\Models\Post` | List with filters/sort/pagination |
| `POST` | `/query?model=App\Models\Post` | Create (requires `actions.create`) |
| `PATCH` | `/query/{id}?model=App\Models\Post` | Update by route key (requires `actions.update`) |
| `DELETE` | `/query/{id}?model=App\Models\Post` | Delete by route key (requires `actions.delete`) |
| `GET` | `/query/{id}?model=App\Models\Post` | Detail by route key (requires `actions.detail`) |

**Alias-based endpoints** (when `->alias('posts')` is configured):

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/query/posts` | List with filters/sort/pagination |
| `POST` | `/query/posts` | Create |
| `GET` | `/query/posts/{id}` | Detail by route key (simplified) |
| `PATCH` | `/query/posts/{id}` | Update by route key |
| `DELETE` | `/query/posts/{id}` | Delete by route key |
| `*` | `/query/posts/{action}` | Custom action without model binding |
| `*` | `/query/posts/{id}/{action}` | Custom action with model route binding |

**Note:** The simplified `GET /query/posts/{id}` route automatically detects whether the `{id}` segment is a registered custom action name. If it matches an action (e.g., `fetch-all`), the action is executed. Otherwise, it calls the `detail` action with the value as the identifier.

The custom action routes honour the HTTP verb declared by `method()` in your action class. For example, a `publish` action with `method(): 'POST'` is accessible at `POST /query/posts/publish`, while an action that operates on a specific record (like `archive`) can be called at `POST /query/posts/123/archive`.

When `validation` rules exist, the validated payload is passed to the default handler (and to custom `handle` closures). Without rules, the raw request data is used.

If you leave `actions` undefined or omit a specific action, the corresponding endpoint responds with HTTP 405.

## Middleware Pipeline

Query Gate resolves the configured model, then runs any middleware defined in `config/query-gate.php` for that model before executing the query. Use this to enforce authentication, tenancy, throttling, or custom guards per dataset.

## Frontend SDK

Query Gate provides an official TypeScript SDK for frontend applications. It offers a contract-driven, type-safe way to interact with your Query Gate API.

### Installation

```bash
npm install laravel-query-gate-sdk
# or
yarn add laravel-query-gate-sdk
# or
pnpm add laravel-query-gate-sdk
```

GitHub: [https://github.com/behindSolution/laravel-query-gate-sdk](https://github.com/behindSolution/laravel-query-gate-sdk)

### Features

- **Contract-Driven**: One contract per resource defines all operations
- **Type-Safe**: Full TypeScript support with compile-time validation
- **Fluent API**: Chainable, immutable builder pattern
- **Laravel-Native Error Handling**: Built-in support for all Laravel error responses
- **Zero Runtime Overhead**: Contracts exist only for TypeScript, no reflection
- **Framework Agnostic**: Works with React, Vue, Angular, Svelte, or Node.js

### Configuration

```typescript
import { configureQueryGate, queryGate } from 'laravel-query-gate-sdk'

// Global configuration
configureQueryGate({
  baseUrl: 'https://api.example.com/query',
  defaultHeaders: {
    'Authorization': 'Bearer token',
  },
  defaultFetchOptions: {
    credentials: 'include',
    mode: 'cors',
  },
})
```

For multi-tenant or isolated instances:

```typescript
import { createQueryGate } from 'laravel-query-gate-sdk'

const tenantApi = createQueryGate({
  baseUrl: 'https://tenant1.api.example.com/query',
})
```

### Defining Contracts

Contracts define the shape of your API resources with full TypeScript support:

```typescript
import { ResourceContract } from 'laravel-query-gate-sdk'

interface Post {
  id: number
  title: string
  content: string
  status: 'draft' | 'published'
  created_at: string
}

interface CreatePostPayload {
  title: string
  content: string
}

interface UpdatePostPayload {
  title?: string
  content?: string
  status?: 'draft' | 'published'
}

interface PostContract extends ResourceContract {
  get: Post[]
  create: CreatePostPayload
  update: UpdatePostPayload

  actions: {
    publish: {
      method: 'post'
      payload?: never
      response: Post
    }
    bulkPublish: {
      method: 'post'
      payload: { ids: number[] }
      response: { updated: number }
    }
  }
}
```

### Read Operations

```typescript
// Fetch all posts
const posts = await queryGate<PostContract>('posts').get()

// Fetch single post by ID (uses detail action)
const post = await queryGate<PostContract>('posts').id(1).get()

// With filters and sorting
const publishedPosts = await queryGate<PostContract>('posts')
  .filter('status', 'eq', 'published')
  .filter('created_at', 'gte', '2024-01-01')
  .sort('created_at', 'desc')
  .get()
```

### Write Operations

```typescript
// Create
const newPost = await queryGate<PostContract>('posts').post({
  title: 'My New Post',
  content: 'Hello, world!',
})

// Update
const updatedPost = await queryGate<PostContract>('posts')
  .id(1)
  .patch({
    title: 'Updated Title',
    status: 'published',
  })

// Delete
await queryGate<PostContract>('posts').id(1).delete()
```

### Custom Actions

```typescript
// Action without payload: POST /query/posts/1/publish
const publishedPost = await queryGate<PostContract>('posts')
  .id(1)
  .action('publish')
  .post()

// Action with payload: POST /query/posts/bulk-publish
const result = await queryGate<PostContract>('posts')
  .action('bulkPublish')
  .post({ ids: [1, 2, 3, 4, 5] })

// GET action: GET /query/posts/stats
const stats = await queryGate<PostContract>('posts')
  .action('stats')
  .get()
```

### Query Builder Methods

| Method | Description |
|--------|-------------|
| `.filter(field, operator, value)` | Add filters (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `not_in`, `between`) |
| `.sort(field, direction)` | Sort by field (`asc` or `desc`) |
| `.id(value)` | Target specific resource by ID |
| `.action(name)` | Call custom action endpoint |
| `.get()` | Execute GET request |
| `.post(payload)` | Execute POST request |
| `.patch(payload)` | Execute PATCH request |
| `.delete()` | Execute DELETE request |

### Type Safety

TypeScript enforces correct usage at compile time:

```typescript
// ✓ Correct - publish action doesn't require payload
await queryGate<PostContract>('posts').id(1).action('publish').post()

// ✗ Error - bulkPublish requires { ids: number[] }
await queryGate<PostContract>('posts').action('bulkPublish').post()

// ✗ Error - 'invalid' is not a valid status
await queryGate<PostContract>('posts').id(1).patch({ status: 'invalid' })
```

## Testing

Query Gate relies on Laravel's pagination and query builder. You can write integration tests in the host application by hitting the registered route and asserting the JSON structure or pagination metadata returned for your configured models.

Run the package test suite locally with:

```bash
php artisan test --display-deprecations --testdox
```

