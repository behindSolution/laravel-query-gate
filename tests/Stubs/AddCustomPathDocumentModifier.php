<?php

namespace BehindSolution\LaravelQueryGate\Tests\Stubs;

use BehindSolution\LaravelQueryGate\Contracts\OpenApi\DocumentModifier;

class AddCustomPathDocumentModifier implements DocumentModifier
{
    public function modify(array $document): array
    {
        $document['paths']['/users/duplicate'] = [
            'post' => [
                'summary' => 'Duplicate user',
                'description' => 'Creates a duplicate of an existing user.',
                'tags' => ['Users'],
                'responses' => [
                    '201' => [
                        'description' => 'User duplicated successfully.',
                    ],
                ],
            ],
        ];

        return $document;
    }
}


