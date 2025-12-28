<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\TestCase;

class SwaggerRouteTest extends TestCase
{
    public function testSwaggerJsonRouteReturnsDocument(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make());

        $response = $this->get('/query/docs.json');

        $response->assertOk();
        $response->assertJsonFragment([
            'openapi' => '3.1.0',
        ]);
    }

    public function testSwaggerUiRouteRendersHtml(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.openAPI.title', 'Docs UI');
        config()->set('query-gate.models.' . Post::class, QueryGate::make());

        $response = $this->get('/query/docs');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('Redoc.init', false);
        $response->assertSee('Docs UI', false);
    }

    public function testSwaggerJsonRouteAppliesDocumentModifiers(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.openAPI.modifiers', [
            static function (array $document): array {
                $document['paths']['/custom-endpoint'] = [
                    'get' => [
                        'summary' => 'Custom endpoint',
                    ],
                ];

                return $document;
            },
        ]);

        config()->set('query-gate.models.' . Post::class, QueryGate::make());

        $response = $this->get('/query/docs.json');

        $response->assertOk();
        $response->assertJsonPath('paths./custom-endpoint.get.summary', 'Custom endpoint');
    }
}


