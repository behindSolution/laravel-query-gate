<?php

namespace BehindSolution\LaravelQueryGate\Tests\Stubs\Actions;

use BehindSolution\LaravelQueryGate\Actions\AbstractQueryGateAction;

class ArchivePostAction extends AbstractQueryGateAction
{
    public function action(): string
    {
        return 'archive';
    }

    public function method(): string
    {
        return 'DELETE';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle($request, $model, array $payload)
    {
        return [
            'archived' => true,
        ];
    }
}
