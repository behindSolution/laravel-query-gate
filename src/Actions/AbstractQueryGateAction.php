<?php

namespace BehindSolution\LaravelQueryGate\Actions;

use BehindSolution\LaravelQueryGate\Contracts\QueryGateAction;

abstract class AbstractQueryGateAction implements QueryGateAction
{
    public function action(): string
    {
        return 'create';
    }

    public function method(): string
    {
        return 'POST';
    }

    public function status(): ?int
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function validations(): array
    {
        return [];
    }

    /**
     * @return array<int, string>|string|null
     */
    public function policy()
    {
        return null;
    }

    public function authorize($request, $model): ?bool
    {
        return null;
    }

    public function name(): ?string
    {
        return null;
    }

    /**
     * Custom examples for OpenAPI request body documentation.
     * Override this method to provide specific examples for your action.
     *
     * @return array<string, mixed>
     */
    public function openapiRequest(): array
    {
        return [];
    }

    /**
     * Configure an idempotency lock to prevent duplicate execution.
     *
     * Return true for defaults (5s TTL, no user scope),
     * an array for custom config (e.g. ['ttl' => 30, 'forUser' => true]),
     * or false to disable (default).
     *
     * @return bool|array{ttl?: int, forUser?: bool}
     */
    public function lockable(): bool|array
    {
        return false;
    }

    /**
     * Override to provide a custom lock key.
     *
     * When lockable() is enabled and this method is overridden,
     * it replaces the default key generation logic.
     *
     * @return string|null Return a key string, or null to use the default key.
     */
    public function lockKey($request, string $modelClass, string $action, ?string $identifier): ?string
    {
        return null;
    }
}
