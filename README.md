# Laravel Query Gate

[![Latest Version](https://img.shields.io/packagist/v/behindsolution/laravel-query-gate.svg)](https://packagist.org/packages/behindsolution/laravel-query-gate)
[![Tests](https://github.com/behindSolution/QueryGate/actions/workflows/tests.yml/badge.svg)](https://github.com/behindSolution/QueryGate/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/behindsolution/laravel-query-gate.svg)](https://packagist.org/packages/behindsolution/laravel-query-gate)
[![License](https://img.shields.io/packagist/l/behindsolution/laravel-query-gate.svg)](LICENSE)

A lightweight, declarative API builder for Laravel with **API versioning**, **frontend SDK**, and **zero boilerplate**.

## Why Query Gate?

| Feature        | Query Gate | Laravel Orion |
|----------------|----------|---------------|
| API Versioning | ✅ Built-in | ❌             |
| Open API       | ✅ | ❌             |
| Laravel 9+     | ✅ | ❌             |
| Zero Config    | ✅ | ⚠️            |

## Quick Start
```php
use BehindSolution\LaravelQueryGate\Traits\HasQueryGate;

class User extends Model
{
    use HasQueryGate;
    
    public static function queryGate(): QueryGate
    {
        return QueryGate::make()
            ->filters(['name' => 'string', 'email' => 'email'])
            ->allowedFilters(['name' => ['like'], 'email' => ['eq']])
            ->sorts(['name', 'created_at']);
    }
}
```
```bash
# That's it! Your API is ready:
GET /query/users?filter[name][like]=John&sort=-created_at
```

## Ecosystem

- [Documentation](https://laravelquerygate.com)
- [Example Project](https://github.com/behindSolution/LQG-example)
- [Frontend SDK](https://github.com/behindSolution/laravel-query-gate-sdk)
- [Discord Community](https://discord.gg/nrRyvxVf)

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 📄 License

MIT