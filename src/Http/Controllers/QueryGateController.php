<?php

namespace BehindSolution\LaravelQueryGate\Http\Controllers;

use BehindSolution\LaravelQueryGate\Actions\ActionExecutor;
use BehindSolution\LaravelQueryGate\Http\Middleware\ResolveModelMiddleware;
use BehindSolution\LaravelQueryGate\Query\QueryContext;
use BehindSolution\LaravelQueryGate\Query\QueryExecutor;
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
        if ($custom = $this->resolveRequestedAction($request, 'create')) {
            return $this->executeAction($request, $custom);
        }

        return $this->executeAction($request, 'create');
    }

    public function update(Request $request, string $id)
    {
        if ($custom = $this->resolveRequestedAction($request, 'update')) {
            return $this->executeAction($request, $custom, $id);
        }

        return $this->executeAction($request, 'update', $id);
    }

    public function destroy(Request $request, string $id)
    {
        if ($custom = $this->resolveRequestedAction($request, 'delete')) {
            return $this->executeAction($request, $custom, $id);
        }

        return $this->executeAction($request, 'delete', $id);
    }

    public function patchOrAction(Request $request)
    {
        $param = $request->route('param');

        if ($this->isRegisteredAction($request, $param)) {
            return $this->executeAction($request, $param);
        }

        return $this->update($request, $param);
    }

    public function deleteOrAction(Request $request)
    {
        $param = $request->route('param');

        if ($this->isRegisteredAction($request, $param)) {
            return $this->executeAction($request, $param);
        }

        return $this->destroy($request, $param);
    }

    public function action(Request $request, string $model, string $action)
    {
        return $this->executeAction($request, $action);
    }

    public function changelog(Request $request)
    {
        $configuration = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, []);
        $versions = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_VERSIONS);
        $model = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_MODEL);
        $active = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_VERSION);

        if (!is_array($versions) || ($versions['definitions'] ?? []) === []) {
            return response()->json([
                'model' => $model,
                'alias' => is_array($configuration) && isset($configuration['alias']) ? $configuration['alias'] : null,
                'default' => null,
                'active' => $active,
                'versions' => [],
            ]);
        }

        $order = is_array($versions['order'] ?? null)
            ? $versions['order']
            : array_keys($versions['definitions']);

        $changelog = is_array($versions['changelog'] ?? null) ? $versions['changelog'] : [];

        $timeline = [];

        foreach ($order as $identifier) {
            if (!is_string($identifier) || $identifier === '') {
                continue;
            }

            $timeline[] = [
                'version' => $identifier,
                'changes' => $changelog[$identifier] ?? [],
            ];
        }

        return response()->json([
            'model' => $model,
            'alias' => is_array($configuration) && isset($configuration['alias']) ? $configuration['alias'] : null,
            'default' => $versions['default'] ?? null,
            'active' => $active ?? ($versions['default'] ?? null),
            'versions' => $timeline,
        ]);
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

    protected function executeAction(Request $request, string $action, ?string $identifier = null)
    {
        [$model, $configuration] = $this->resolveActionContext($request);

        return $this->actionExecutor->execute($action, $request, $model, $configuration, $identifier);
    }

    protected function resolveRequestedAction(Request $request, ?string $default = null): ?string
    {
        $action = $request->query('action');

        if (!is_string($action)) {
            return null;
        }

        $action = trim($action);

        if ($action === '') {
            return null;
        }

        if ($default !== null && strcasecmp($action, $default) === 0) {
            return null;
        }

        return $action;
    }

    protected function isRegisteredAction(Request $request, string $param): bool
    {
        if (in_array($param, ['create', 'update', 'delete'], true)) {
            return false;
        }

        $configuration = $request->attributes->get(ResolveModelMiddleware::ATTRIBUTE_CONFIGURATION, []);
        $actions = $configuration['actions'] ?? [];

        if (!is_array($actions)) {
            return false;
        }

        return array_key_exists($param, $actions);
    }
}

