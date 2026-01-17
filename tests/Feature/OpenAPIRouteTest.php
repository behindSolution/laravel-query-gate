<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\PostResource;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\BulkPublishPostsAction;
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

    public function testOpenApiDetectsActionThatUsesModel(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->use(PublishPostAction::class) // Uses $model in handle
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // PublishPostAction uses $model, so it should have {id} in the path
        $this->assertArrayHasKey('/query/posts/{id}/publish', $data['paths']);
        $this->assertArrayNotHasKey('/query/posts/publish', $data['paths']);
    }

    public function testOpenApiDetectsActionThatDoesNotUseModel(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->use(BulkPublishPostsAction::class) // Does NOT use $model in handle
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // BulkPublishPostsAction doesn't use $model, so it should NOT have {id} in the path
        $this->assertArrayHasKey('/query/posts/bulk-publish', $data['paths']);
        $this->assertArrayNotHasKey('/query/posts/{id}/bulk-publish', $data['paths']);
    }

    public function testOpenApiDetectsClosureThatDoesNotUseModel(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['ids' => 'required|array'])
                    ->handle(function ($request, $model, $payload) {
                        // Only uses $request and $payload, not $model
                        return ['processed' => count($payload['ids'] ?? [])];
                    })
                )
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // The closure doesn't use $model, but create action is always without {id}
        $this->assertArrayHasKey('/query/posts', $data['paths']);
        $this->assertArrayHasKey('post', $data['paths']['/query/posts']);
    }

    public function testOpenApiExtractsFieldsFromResource(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->select(PostResource::class)
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // Find the example in the GET response
        $listPath = $data['paths']['/query/posts'];
        $example = $listPath['get']['responses']['200']['content']['application/json']['example'] ?? [];

        // The example should contain fields from PostResource
        $this->assertArrayHasKey('data', $example);
        $this->assertIsArray($example['data']);
        $this->assertNotEmpty($example['data']);

        $record = $example['data'][0];

        // PostResource returns: id, title, formatted_title
        $this->assertArrayHasKey('id', $record);
        $this->assertArrayHasKey('title', $record);
        $this->assertArrayHasKey('formatted_title', $record);
    }

    public function testOpenApiResourceFieldsHaveInferredTypes(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->select(PostResource::class)
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        $listPath = $data['paths']['/query/posts'];
        $example = $listPath['get']['responses']['200']['content']['application/json']['example'] ?? [];
        $record = $example['data'][0] ?? [];

        // id should be inferred as integer
        $this->assertSame(1, $record['id']);
    }

    public function testOpenApiCustomExamplesOverrideInferredValues(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title', 'author_name'])
            ->openapiResponse([
                'id' => 42,
                'title' => 'My Amazing Post',
                'author_name' => 'John Doe',
            ])
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        $listPath = $data['paths']['/query/posts'];
        $example = $listPath['get']['responses']['200']['content']['application/json']['example'] ?? [];
        $record = $example['data'][0] ?? [];

        $this->assertSame(42, $record['id']);
        $this->assertSame('My Amazing Post', $record['title']);
        $this->assertSame('John Doe', $record['author_name']);
    }

    public function testOpenApiCustomExamplesWithDotNotation(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->select(['id', 'title', 'tags.id', 'tags.name'])
            ->openapiResponse([
                'id' => 1,
                'title' => 'Tech Article',
                'tags.id' => 10,
                'tags.name' => 'Technology',
            ])
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        $listPath = $data['paths']['/query/posts'];
        $example = $listPath['get']['responses']['200']['content']['application/json']['example'] ?? [];
        $record = $example['data'][0] ?? [];

        $this->assertSame(1, $record['id']);
        $this->assertSame('Tech Article', $record['title']);
        $this->assertIsArray($record['tags']);
        $this->assertSame(10, $record['tags'][0]['id']);
        $this->assertSame('Technology', $record['tags'][0]['name']);
    }

    public function testOpenApiCustomExamplesWithResourceClass(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->select(PostResource::class)
            ->openapiResponse([
                'id' => 999,
                'title' => 'Custom Title',
                'formatted_title' => 'CUSTOM TITLE',
            ])
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        $listPath = $data['paths']['/query/posts'];
        $example = $listPath['get']['responses']['200']['content']['application/json']['example'] ?? [];
        $record = $example['data'][0] ?? [];

        // Custom examples should override inferred values from Resource
        $this->assertSame(999, $record['id']);
        $this->assertSame('Custom Title', $record['title']);
        $this->assertSame('CUSTOM TITLE', $record['formatted_title']);
    }

    public function testOpenApiCustomExamplesWithVersions(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->version('2024-01-01', fn ($gate) => $gate
                ->select(['id', 'title'])
                ->openapiResponse([
                    'id' => 1,
                    'title' => 'Version 1 Title',
                ])
            )
            ->version('2024-06-01', fn ($gate) => $gate
                ->select(['id', 'title', 'status'])
                ->openapiResponse([
                    'id' => 2,
                    'title' => 'Version 2 Title',
                    'status' => 'published',
                ])
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        $listPath = $data['paths']['/query/posts'];
        $example = $listPath['get']['responses']['200']['content']['application/json']['example'] ?? [];
        $record = $example['data'][0] ?? [];

        // Should use the latest version's examples (2024-06-01)
        $this->assertSame(2, $record['id']);
        $this->assertSame('Version 2 Title', $record['title']);
        $this->assertSame('published', $record['status']);
    }

    public function testOpenApiRequestExamplesForInlineAction(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations(['title' => 'required', 'content' => 'required'])
                    ->openapiRequest([
                        'title' => 'My New Post',
                        'content' => 'This is the content of my post.',
                    ])
                )
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // Debug: show available schema names
        $schemaNames = array_keys($data['components']['schemas'] ?? []);

        // Find the correct schema name for Post create action
        $matchingSchema = null;
        foreach ($schemaNames as $name) {
            if (str_contains(strtolower($name), 'create')) {
                $matchingSchema = $name;
                break;
            }
        }

        $this->assertNotNull($matchingSchema, 'No create schema found. Available: ' . implode(', ', $schemaNames));

        $schema = $data['components']['schemas'][$matchingSchema];

        $this->assertArrayHasKey('example', $schema, 'Schema: ' . json_encode($schema));
        $this->assertSame('My New Post', $schema['example']['title']);
        $this->assertSame('This is the content of my post.', $schema['example']['content']);
    }

    public function testOpenApiRequestExamplesForCustomAction(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->use(PublishPostAction::class)
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // Find the publish action request body
        $publishPath = $data['paths']['/query/posts/{id}/publish']['post'] ?? [];
        $requestBody = $publishPath['requestBody']['content']['application/json']['schema'] ?? [];

        $this->assertArrayHasKey('example', $requestBody);
        $this->assertSame('2024-06-01T10:00:00Z', $requestBody['example']['scheduled_at']);
        $this->assertTrue($requestBody['example']['notify_subscribers']);
    }

    public function testOpenApiRequestExamplesReplacesValidationFields(): void
    {
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->validations([
                        'title' => 'required|string|max:255',
                        'content' => 'required|string',
                        'status' => 'string',
                    ])
                    ->openapiRequest([
                        'title' => 'Example Title',
                        'status' => 'draft',
                        // 'content' not included - won't appear in example
                    ])
                )
            )
        );

        $response = $this->get('/query/docs.json');

        $response->assertOk();

        $data = $response->json();

        // Find the correct schema name for Post create action
        $schemaNames = array_keys($data['components']['schemas'] ?? []);
        $matchingSchema = null;
        foreach ($schemaNames as $name) {
            if (str_contains(strtolower($name), 'create')) {
                $matchingSchema = $name;
                break;
            }
        }

        $this->assertNotNull($matchingSchema, 'No create schema found. Available: ' . implode(', ', $schemaNames));

        $schema = $data['components']['schemas'][$matchingSchema];

        $this->assertArrayHasKey('example', $schema);
        $this->assertSame('Example Title', $schema['example']['title']);
        $this->assertSame('draft', $schema['example']['status']);
        // content is not in openapiRequest, so it won't appear (no merge with validation fields)
        $this->assertArrayNotHasKey('content', $schema['example']);
    }
}


