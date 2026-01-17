<?php

namespace BehindSolution\LaravelQueryGate\Tests\Stubs\Actions;

use BehindSolution\LaravelQueryGate\Actions\AbstractQueryGateAction;

/**
 * Example of a bulk action that doesn't use the $model parameter.
 * This should be detected by static analysis and not require {id} in the URL.
 */
class BulkPublishPostsAction extends AbstractQueryGateAction
{
    public function action(): string
    {
        return 'bulk-publish';
    }

    public function method(): string
    {
        return 'POST';
    }

    public function validations(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ];
    }

    /**
     * Note: This handle does NOT use $model - it operates on multiple records.
     *
     * @param array<string, mixed> $payload
     */
    public function handle($request, $model, array $payload)
    {
        // Only uses $request and $payload, not $model
        $ids = $payload['ids'] ?? [];

        return [
            'published_count' => count($ids),
            'ids' => $ids,
        ];
    }
}
