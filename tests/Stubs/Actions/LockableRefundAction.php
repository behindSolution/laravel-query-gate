<?php

namespace BehindSolution\LaravelQueryGate\Tests\Stubs\Actions;

use BehindSolution\LaravelQueryGate\Actions\AbstractQueryGateAction;

class LockableRefundAction extends AbstractQueryGateAction
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
     */
    public function handle($request, $model, array $payload)
    {
        return [
            'refunded' => true,
            'payload' => $payload,
        ];
    }

    public function validations(): array
    {
        return [
            'reason' => ['required', 'string'],
        ];
    }

    public function lockable(): bool|array
    {
        return ['ttl' => 10, 'forUser' => true];
    }
}
