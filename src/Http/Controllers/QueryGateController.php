<?php

namespace BehindSolution\QueryGate\Http\Controllers;

use BehindSolution\QueryGate\Actions\ActionExecutor;
use BehindSolution\QueryGate\Http\Middleware\ResolveModelMiddleware;
use BehindSolution\QueryGate\Query\QueryContext;
use BehindSolution\QueryGate\Query\QueryExecutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class QueryGateController
{
    protected QueryExecutor $queryExecutor;

    protected ActionExecutor $actionExecutor;

    public function __construct(QueryExecutor $queryExecutor, ActionExecutor $actionExecutor)
    {
        $this->queryExecutor = $queryExecutor;
        $this->actionExecutor = $actionExecutor;
    }

    public function index(Request $request)
    {
        [$model, $configuration, $builder] = $this->resolveQueryContext($request);

        $context = new QueryContext($model, $request, $builder);

        return $this->queryExecutor->execute($context, $configuration);
    }

    public function store(Request $request)
    {
        [$model, $configuration] = $this->resolveActionContext($request);

        return $this->actionExecutor->execute('create', $request, $model, $configuration);
    }

    public function update(Request $request, string $id)
    {
        [$model, $configuration] = $this->resolveActionContext($request);

        return $this->actionExecutor->execute('update', $request, $model, $configuration, $id);
    }

    public function destroy(Request $request, string $id)
    {
        [$model, $configuration] = $this->resolveActionContext($request);

        return $this->actionExecutor->execute('delete', $request, $model, $configuration, $id);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: Builder}
     */
    protected function resolveQueryContext(Request $request): array
    {
        $model = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_MODEL);
        $configuration = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, []);
        $builder = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_BUILDER);

        if (!is_string($model) || !$builder instanceof Builder) {
            throw new HttpException(500, 'Unable to resolve Query Gate context.');
        }

        return [$model, is_array($configuration) ? $configuration : [], $builder];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function resolveActionContext(Request $request): array
    {
        $model = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_MODEL);
        $configuration = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, []);

        if (!is_string($model)) {
            throw new HttpException(500, 'Unable to resolve Query Gate model.');
        }

        return [$model, is_array($configuration) ? $configuration : []];
    }
}

