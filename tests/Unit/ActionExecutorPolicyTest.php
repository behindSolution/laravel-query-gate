<?php

namespace BehindSolution\LaravelQueryGate\Tests\Unit;

use BehindSolution\LaravelQueryGate\Actions\ActionExecutor;
use BehindSolution\LaravelQueryGate\Support\QueryGate;
use BehindSolution\LaravelQueryGate\Tests\Fixtures\Post;
use BehindSolution\LaravelQueryGate\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActionExecutorPolicyTest extends TestCase
{
    public function testAllowsActionWhenPolicyAuthorizes(): void
    {
        $request = Request::create('/query', 'POST');
        $request->setUserResolver(static fn () => new GenericUser(['id' => 1]));

        $executor = new ActionExecutor();

        $configuration = QueryGate::make()
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->policy('create')
                    ->handle(static fn () => 'created')
                )
            )
            ->toArray();

        $result = $executor->execute('create', $request, Post::class, $configuration);

        $this->assertSame('created', $result);
    }

    public function testDeniesActionWhenPolicyRejects(): void
    {
        $request = Request::create('/query', 'POST');
        $request->setUserResolver(static fn () => new GenericUser(['id' => 1]));

        $executor = new ActionExecutor();

        $configuration = QueryGate::make()
            ->actions(fn ($actions) => $actions
                ->create(fn ($action) => $action
                    ->policy('createRestricted')
                    ->handle(static fn () => 'should not run')
                )
            )
            ->toArray();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $executor->execute('create', $request, Post::class, $configuration);
    }
}


