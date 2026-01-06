<?php

namespace BehindSolution\LaravelQueryGate\Tests\Stubs\Actions;

use BehindSolution\LaravelQueryGate\Actions\AbstractQueryGateAction;

class CreatePostAction extends AbstractQueryGateAction
{
    public function action(): string
    {
        return 'create';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle($request, $model, array $payload)
    {
        return [
            'handled' => true,
            'payload' => $payload,
        ];
    }

    public function status(): ?int
    {
        return 202;
    }

    /**
     * @return array<string, mixed>
     */
    public function validations(): array
    {
        return [
            'title' => ['required', 'string'],
        ];
    }

    public function authorize($request, $model): ?bool
    {
        return true;
    }

    public function name(): ?string
    {
        return 'Create Post';
    }
}
