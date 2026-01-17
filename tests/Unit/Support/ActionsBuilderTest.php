<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit\Support;

use BehindSolution\LaravelQueryGate\Support\ActionsBuilder;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\ArchivePostAction;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\CreatePostAction;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\PublishPostAction;
use BehindSolution\LaravelQueryGate\Tests\TestCase;

class ActionsBuilderTest extends TestCase
{
    public function testUseRegistersActionClass(): void
    {
        $builder = new ActionsBuilder();

        $builder->use(CreatePostAction::class);

        $actions = $builder->toArray();

        $this->assertArrayHasKey('create', $actions);
        $this->assertArrayHasKey('validation', $actions['create']);
        $this->assertArrayHasKey('handle', $actions['create']);
        $this->assertArrayHasKey('status', $actions['create']);
        $this->assertSame('POST', $actions['create']['method']);
        $this->assertSame(CreatePostAction::class, $actions['create']['class']);
        $this->assertSame([
            'title' => ['required', 'string'],
        ], $actions['create']['validation']);
    }

    public function testCreateActionDefaultsToPost(): void
    {
        $builder = new ActionsBuilder();

        $builder->create();

        $actions = $builder->toArray();

        $this->assertSame('POST', $actions['create']['method']);
    }

    public function testCustomActionIsRegistered(): void
    {
        $builder = new ActionsBuilder();

        $builder->use(PublishPostAction::class);

        $actions = $builder->toArray();

        $this->assertArrayHasKey('publish', $actions);
        $this->assertSame('POST', $actions['publish']['method']);
        $this->assertSame(PublishPostAction::class, $actions['publish']['class']);
    }

    public function testCustomActionCanOverrideHttpMethod(): void
    {
        $builder = new ActionsBuilder();

        $builder->use(ArchivePostAction::class);

        $actions = $builder->toArray();

        $this->assertArrayHasKey('archive', $actions);
        $this->assertSame('DELETE', $actions['archive']['method']);
    }

    public function testOpenapiRequestSetsExamples(): void
    {
        $builder = new ActionsBuilder();

        $builder->create(fn ($action) => $action
            ->validations(['title' => 'required', 'content' => 'required'])
            ->openapiRequest([
                'title' => 'Example Title',
                'content' => 'Example Content',
            ])
        );

        $actions = $builder->toArray();

        $this->assertArrayHasKey('create', $actions);
        $this->assertArrayHasKey('openapi_request', $actions['create']);
        $this->assertSame('Example Title', $actions['create']['openapi_request']['title']);
        $this->assertSame('Example Content', $actions['create']['openapi_request']['content']);
    }
}
