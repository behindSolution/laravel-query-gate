<?php

namespace BehindSolution\LaravelQueryGate\Tests\Feature;

use BehindSolution\LaravelQueryGate\Actions\ActionExecutor;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\ArchivePostAction;
use BehindSolution\LaravelQueryGate\Tests\Stubs\Actions\CreatePostAction;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActionExecutorTest extends TestCase
{
    public function testExecutesClassBasedAction(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(CreatePostAction::class))
            ->toArray();

        config()->set('query-gate.models.' . Post::class, $definition);

        $configuration = $definition;

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'POST', [
            'model' => Post::class,
            'title' => 'Sample Title',
        ]);
        $request->headers->set('Accept', 'application/json');

        /** @var JsonResponse $response */
        $response = $executor->execute('create', $request, Post::class, $configuration);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame([
            'handled' => true,
            'payload' => [
                'title' => 'Sample Title',
            ],
        ], $response->getData(true));
    }

    public function testThrowsWhenHttpMethodDoesNotMatch(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(ArchivePostAction::class))
            ->toArray();

        $executor = new ActionExecutor();
        $configuration = $definition;

        $request = Request::create('/query', 'POST', [
            'model' => Post::class,
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Action "archive" must be invoked using the DELETE method.');

        $executor->execute('archive', $request, Post::class, $configuration);
    }

    public function testExecutesCustomActionWithMatchingMethod(): void
    {
        $definition = QueryGate::make()
            ->alias('posts')
            ->actions(fn ($actions) => $actions->use(ArchivePostAction::class))
            ->toArray();

        $executor = new ActionExecutor();

        $request = Request::create('/query', 'DELETE', [
            'model' => Post::class,
        ]);
        $request->headers->set('Accept', 'application/json');

        $result = $executor->execute('archive', $request, Post::class, $definition);

        $this->assertSame(['archived' => true], $result);
    }
}
