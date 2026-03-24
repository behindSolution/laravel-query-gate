# Laravel Query Gate

[![Latest Version](https://img.shields.io/packagist/v/behindsolution/laravel-query-gate.svg)](https://packagist.org/packages/behindsolution/laravel-query-gate)
[![Tests](https://github.com/behindSolution/QueryGate/actions/workflows/tests.yml/badge.svg)](https://github.com/behindSolution/QueryGate/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/behindsolution/laravel-query-gate.svg)](https://packagist.org/packages/behindsolution/laravel-query-gate)
[![License](https://img.shields.io/packagist/l/behindsolution/laravel-query-gate.svg)](LICENSE)

A declarative API layer for Laravel that turns Eloquent models into fully featured REST endpoints — with filtering, sorting, actions, versioning, OpenAPI docs, and a type-safe TypeScript SDK.

## Features

- **Declarative API definition** — define filters, sorts, selects, and actions directly on your model
- **CRUD actions** — built-in create, update, delete, and detail with validation, policies, and custom handlers
- **Custom actions** — extend your API with reusable action classes
- **API versioning** — version your endpoints with automatic changelog generation
- **OpenAPI documentation** — auto-generated spec and UI out of the box
- **TypeScript code generation** — generate type-safe contracts with `php artisan qg:types`
- **Frontend SDK** — fully typed, framework-agnostic query builder for the frontend
- **Caching & pagination** — cursor and offset pagination, built-in cache support

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

```bash
composer require behindsolution/laravel-query-gate
```

Publish the config file:

```bash
php artisan vendor:publish --tag=query-gate-config
```

## Quick Start

Add the trait to your model and define the query gate:

```php
use BehindSolution\LaravelQueryGate\Traits\HasQueryGate;
use BehindSolution\LaravelQueryGate\Support\QueryGate;

class User extends Model
{
    use HasQueryGate;

    public static function queryGate(): QueryGate
    {
        return QueryGate::make()
            ->alias('users')
            ->select(['id', 'name', 'email', 'created_at'])
            ->filters(['name' => 'string', 'email' => 'email'])
            ->allowedFilters(['name' => ['like', 'eq'], 'email' => ['eq']])
            ->sorts(['name', 'created_at']);
    }
}
```

Your API is ready:

```
GET    /query/users?filter[name][like]=John&sort=-created_at
GET    /query/users/1
```

## Actions

Define mutations with a fluent builder:

```php
QueryGate::make()
    ->alias('posts')
    ->actions(fn ($actions) => $actions
        ->create(fn ($action) => $action
            ->validations(['title' => 'required|string', 'body' => 'required|string'])
            ->policy('create')
        )
        ->update(fn ($action) => $action
            ->validations(['title' => 'string', 'body' => 'string'])
            ->policy('update')
        )
        ->delete(fn ($action) => $action->policy('delete'))
        ->detail()
    );
```

Custom actions:

```php
->actions(fn ($actions) => $actions
    ->use(PublishPostAction::class)
)
```

## API Versioning

```php
QueryGate::make()
    ->alias('users')
    ->version('2024-01-01', fn ($gate) => $gate
        ->select(['id', 'name', 'email'])
        ->filters(['name' => 'string'])
    )
    ->version('2024-06-01', fn ($gate) => $gate
        ->select(['id', 'name', 'email', 'avatar'])
        ->filters(['name' => 'string', 'email' => 'email'])
    );
```

Clients select a version via the `X-Query-Version` header or `?version=` query parameter. A changelog is auto-generated at `GET /query/users/__changelog`.

## TypeScript Code Generation

Generate type-safe contracts from your API definitions:

```bash
php artisan qg:types --output=resources/ts/contracts
```

Output example:

```typescript
export interface UserEntity {
  id: number;
  name: string;
  email: string;
}

export interface UserCreatePayload {
  name: string;
  email: string;
}

export interface UserResourceContract {
  get: UserEntity;
  create: { payload: UserCreatePayload; response: UserEntity };
  // ...
}
```

## Frontend SDK

Install the companion SDK:

```bash
npm install laravel-query-gate-sdk
```

```typescript
import { configureQueryGate, queryGate } from 'laravel-query-gate-sdk'

configureQueryGate({ baseUrl: 'https://api.example.com/query' })

// List with filters and sorting
const users = await queryGate<UserResourceContract>('users')
  .filter('name', 'like', 'John')
  .sort('created_at', 'desc')
  .get()

// Create
await queryGate<UserResourceContract>('users')
  .post({ name: 'Jane', email: 'jane@example.com' })

// Update
await queryGate<UserResourceContract>('users').id(1)
  .patch({ name: 'Jane Doe' })

// Custom action
await queryGate<PostResourceContract>('posts').id(1)
  .action('publish').post()
```

## OpenAPI Documentation

Enable in `config/query-gate.php`:

```php
'openAPI' => [
    'enabled' => true,
],
```

Access the generated docs at `GET /query/docs` (UI) or `GET /query/docs.json` (spec).

## Ecosystem

- [Documentation](https://laravelquerygate.com)
- [Example Project](https://github.com/behindSolution/LQG-example)
- [Frontend SDK](https://github.com/behindSolution/laravel-query-gate-sdk)
- [Discord Community](https://discord.gg/ydFBEZ8aTD)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

MIT
