<?php

namespace BehindSolution\LaravelQueryGate\Tests\Stubs\Actions;

use BehindSolution\LaravelQueryGate\Actions\AbstractQueryGateAction;

class ApproveCommentAction extends AbstractQueryGateAction
{
    public function action(): string
    {
        return 'approve';
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
            'approved' => true,
            'comment_id' => $model->getKey(),
            'comment_name' => $model->getAttribute('name'),
        ];
    }
}
