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
}
