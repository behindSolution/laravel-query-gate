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
        );

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
        $this->assertArrayHasKey('/query', $document['paths']);
        $this->assertArrayHasKey('get', $document['paths']['/query']);
        $this->assertArrayHasKey('x-query-gate', $document);
        $this->assertArrayHasKey('models', $document['x-query-gate']);
        $this->assertNotEmpty($document['x-query-gate']['models']);

        $modelMeta = null;

        foreach ($document['x-query-gate']['models'] as $item) {
            if (($item['model'] ?? null) === Post::class) {
                $modelMeta = $item;
                break;
            }
        }

        $this->assertNotNull($modelMeta);
        $this->assertContains('posts', $modelMeta['aliases']);

        $component = $modelMeta['component'] ?? null;
        $this->assertIsString($component);

        $definitionRef = $modelMeta['definition'] ?? null;
        $this->assertIsString($definitionRef);
        $this->assertSame('#/components/schemas/' . $component . 'Definition', $definitionRef);

        $this->assertArrayHasKey($component . 'Definition', $document['components']['schemas']);
        $this->assertArrayHasKey($component . 'CreateRequest', $document['components']['schemas']);

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


