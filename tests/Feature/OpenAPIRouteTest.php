<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\PublishPostAction;
use BehindSolution\LaravelQueryGate\Tests\TestCase;

class OpenAPIRouteTest extends TestCase
{
    public function testOpenApiJsonRouteReturnsDocument(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make());

        $response = $this->get('/query/docs.json');

        $response->assertOk();
        $response->assertJsonFragment([
            'openapi' => '3.1.0',
        ]);
    }

    public function testOpenApiUiRouteRendersHtml(): void
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

    public function testOpenApiJsonRouteAppliesDocumentModifiers(): void
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

    public function testOpenApiDocumentsCustomActions(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create()
                ->use(PublishPostAction::class)
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // Check that custom action path exists
        $this->assertArrayHasKey('/query/posts/{id}/publish', $data['paths']);

        // Check that the action has the correct HTTP method
        $publishPath = $data['paths']['/query/posts/{id}/publish'];
        $this->assertArrayHasKey('post', $publishPath);

        // Check that the operation has correct summary
        $this->assertStringContainsString('Publish', $publishPath['post']['summary']);
    }

    public function testOpenApiDocumentsVersioning(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->version('2024-01-01', fn ($gate) => $gate
                ->filters(['title' => 'string'])
                ->select(['id', 'title'])
            )
            ->version('2024-06-01', fn ($gate) => $gate
                ->filters(['title' => 'string', 'status' => 'string'])
                ->select(['id', 'title', 'status'])
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // Check that version header parameter exists
        $listPath = $data['paths']['/query/posts'];
        $parameters = $listPath['get']['parameters'] ?? [];

        $versionParam = collect($parameters)->firstWhere('name', 'X-Query-Version');

        $this->assertNotNull($versionParam);
        $this->assertSame('header', $versionParam['in']);
        $this->assertContains('2024-01-01', $versionParam['schema']['enum']);
        $this->assertContains('2024-06-01', $versionParam['schema']['enum']);
    }

    public function testOpenApiFiltersHaveImprovedExamples(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->filters([
                'created_at' => 'date',
                'views_count' => 'integer',
                'title' => 'string',
            ])
            ->allowedFilters([
                'created_at' => ['between', 'gte', 'lte'],
                'views_count' => ['gt', 'lt', 'between'],
                'title' => ['like', 'eq'],
            ])
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // Find the filter parameter
        $listPath = $data['paths']['/query/posts'];
        $parameters = $listPath['get']['parameters'] ?? [];
        $filterParam = collect($parameters)->firstWhere('name', 'filter');

        $this->assertNotNull($filterParam);

        // Check date filter has date-appropriate examples
        $createdAtProps = $filterParam['schema']['properties']['created_at']['properties'] ?? [];
        $this->assertStringContainsString('2024', $createdAtProps['between']['example'] ?? '');

        // Check integer filter has numeric examples
        $viewsProps = $filterParam['schema']['properties']['views_count']['properties'] ?? [];
        $this->assertStringContainsString('1', $viewsProps['between']['example'] ?? '');

        // Check string filter has search examples
        $titleProps = $filterParam['schema']['properties']['title']['properties'] ?? [];
        $this->assertStringContainsString('%', $titleProps['like']['example'] ?? '');
    }

    public function testOpenApiDocumentsCustomActionWithoutIdentifier(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required'])
                    ->handle(fn () => ['batch' => true])
                    ->withoutQuery()
                )
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // Check that create action exists on list path (no {id})
        $this->assertArrayHasKey('/query/posts', $data['paths']);
        $this->assertArrayHasKey('post', $data['paths']['/query/posts']);
    }
}


