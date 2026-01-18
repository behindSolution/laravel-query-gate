<?php

namespace BehindSolution\LaravelQueryGate\Tests\Stubs\Actions;

use BehindSolution\LaravelQueryGate\Actions\AbstractQueryGateAction;

class FetchAllAction extends AbstractQueryGateAction
{
    public function action(): string
    {
        return 'fetch-all';
    }

    public function method(): string
    {
        return 'GET';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle($request, $model, array $payload)
    {
        return [
            'custom_action' => true,
            'message' => 'This is a custom GET action',
        ];
    }
}
