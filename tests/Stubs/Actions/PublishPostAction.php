<?php

namespace BehindSolution\LaravelQueryGate\Tests\Stubs\Actions;

use BehindSolution\LaravelQueryGate\Actions\AbstractQueryGateAction;

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

    /**
     * @param array<string, mixed> $payload
     */
    public function handle($request, $model, array $payload)
    {
        // Uses $model to update the status
        $model->status = 'published';
        $model->save();

        return [
            'id' => $model->id,
            'published' => true,
        ];
    }

    public function openapiRequest(): array
    {
        return [
            'scheduled_at' => '2024-06-01T10:00:00Z',
            'notify_subscribers' => true,
        ];
    }
}
