<?php

namespace BehindSolution\LaravelQueryGate\Tests\Stubs\Actions;

use BehindSolution\LaravelQueryGate\Actions\AbstractQueryGateAction;

class LockableCustomKeyAction extends AbstractQueryGateAction
{
    public function action(): string
    {
        return 'charge';
    }

    public function method(): string
    {
        return 'POST';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle($request, $model, array $payload)
    {
        return ['charged' => true];
    }

    public function validations(): array
    {
        return [
            'amount' => ['required', 'numeric'],
        ];
    }

    public function lockable(): bool|array
    {
        return ['ttl' => 15];
    }

    public function lockKey($request, string $modelClass, string $action, ?string $identifier): ?string
    {
        return 'charge:custom:' . $identifier;
    }
}
