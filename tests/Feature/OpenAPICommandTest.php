<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Stubs\AddCustomPathDocumentModifier;
use BehindSolution\LaravelQueryGate\Tests\TestCase;

class OpenAPICommandTest extends TestCase
{
    public function testGeneratesOpenApiDocument(): void
    {
        $outputPath = storage_path('app/query-gate-test-openapi.json');

        @unlink($outputPath);

        config()->set('query-gate.model_aliases', [
            'posts' => Post::class,
        ]);

        config()->set('query-gate.models.' . Post::class, QueryGate::make()
            ->cache(120, 'posts-index')
            ->filters([
                'title' => ['required', 'string', 'max:255'],
            ])
            ->allowedFilters([
                'title' => ['eq', 'like'],
            ])
            ->select(['created_at', 'posts.title'])
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action->validations([
                    'title' => ['required', 'string'],
                    'status' => ['sometimes', 'string'],
                ]))
                ->update(fn ($action) => $action->validations([
                    'status' => ['required', 'in:draft,published'],
                ]))
                ->delete()
            )
            ->sorts(['title', 'created_at'])
        );

        $rawDefinition = config('query-gate.models.' . Post::class);
        $this->assertInstanceOf(QueryGate::class, $rawDefinition);
        $definitionArray = $rawDefinition->toArray();
        $this->assertArrayHasKey('select', $definitionArray);
        $this->assertSame(['created_at', 'posts.title'], $definitionArray['select']);

        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.openAPI.title', 'Test Query Gate');
        config()->set('query-gate.openAPI.output.path', $outputPath);

        $this->artisan('qg:openapi', ['--output' => $outputPath])
            ->assertExitCode(0);

        $this->assertFileExists($outputPath);

        $contents = file_get_contents($outputPath);
        $this->assertIsString($contents);

        $document = json_decode($contents, true);

        $this->assertIsArray($document);
        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame('Test Query Gate', $document['info']['title']);
        $this->assertArrayHasKey('/query/posts', $document['paths']);
        $this->assertArrayHasKey('get', $document['paths']['/query/posts']);
        $this->assertSame('List Posts', $document['paths']['/query/posts']['get']['summary']);
        $this->assertArrayHasKey('/query/posts/{id}', $document['paths']);
        $pathInfo = $document['paths']['/query/posts'];
        $this->assertSame('path', $pathInfo['parameters'][0]['in']);
        $this->assertSame('posts', $pathInfo['parameters'][0]['example']);

        $definitionSchema = $document['components']['schemas']['QueryGateBehindSolutionLaravelQueryGateTestsFixturesPostDefinition'] ?? null;
        $this->assertNotNull($definitionSchema);
        $this->assertArrayHasKey('select', $definitionSchema['properties']);
        $this->assertArrayHasKey('sorts', $definitionSchema['properties']);

        $operation = $pathInfo['get'];

        $filterParam = null;
        foreach ($operation['parameters'] as $parameter) {
            if (($parameter['name'] ?? null) === 'filter') {
                $filterParam = $parameter;
                break;
            }
        }
        $this->assertNotNull($filterParam);
        $this->assertArrayHasKey('schema', $filterParam);
        $this->assertArrayHasKey('title', $filterParam['schema']['properties']);
        $this->assertArrayHasKey('like', $filterParam['schema']['properties']['title']['properties']);

        $example = $operation['responses']['200']['content']['application/json']['example'];
        $this->assertSame('undefined', $example['data'][0]['created_at']);
        $this->assertTrue(isset($example['data'][0]['posts'][0]['title']));

        $createOperation = $document['paths']['/query/posts']['post'];
        $this->assertSame('undefined', $createOperation['responses']['201']['content']['application/json']['example']['created_at'] ?? 'undefined');

        $createRequestSchema = null;
        foreach ($document['components']['schemas'] as $name => $schema) {
            if (str_ends_with($name, 'CreateRequest')) {
                $createRequestSchema = $schema;
                break;
            }
        }
        $this->assertNotNull($createRequestSchema);
        $this->assertArrayHasKey('example', $createRequestSchema);

        @unlink($outputPath);
    }

    public function testCommandAppliesDocumentModifiers(): void
    {
        $outputPath = storage_path('app/query-gate-test-openapi.json');

        @unlink($outputPath);

        config()->set('query-gate.models.' . Post::class, QueryGate::make());
        config()->set('query-gate.openAPI.enabled', true);
        config()->set('query-gate.openAPI.output.path', $outputPath);
        config()->set('query-gate.openAPI.modifiers', [
            AddCustomPathDocumentModifier::class,
        ]);

        $this->artisan('qg:openapi', ['--output' => $outputPath])
            ->assertExitCode(0);

        $document = json_decode((string) file_get_contents($outputPath), true);

        $this->assertArrayHasKey('/users/duplicate', $document['paths']);
        $this->assertArrayHasKey('post', $document['paths']['/users/duplicate']);
        $this->assertSame('Duplicate user', $document['paths']['/users/duplicate']['post']['summary']);

        @unlink($outputPath);
    }
}


