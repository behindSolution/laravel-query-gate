<?php

namespace BehindSolution\LaravelQueryGate\Actions;

use BehindSolution\LaravelQueryGate\Support\CacheRegistry;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActionExecutor
{
    /**
     * @param array<string, mixed> $configuration
     * @return mixed
     */
    public function execute(
        string $action,
        Request $request,
        string $modelClass,
        array $configuration,
        ?string $identifier = null
    ) {
        $actionConfiguration = $this->resolveActionConfiguration($configuration, $action);

        $this->ensureRequestMethod($request, $actionConfiguration, $action);

        $model = $this->resolveModelInstance(
            $action,
            $request,
            $modelClass,
            $configuration,
            $identifier
        );

        $hasCustomHandler = isset($actionConfiguration['handle']) && $actionConfiguration['handle'] instanceof Closure;
        $payload = $this->validatePayload($request, $actionConfiguration, $action, $hasCustomHandler);

        $this->ensureAuthorized($request, $model, $actionConfiguration, $action);

        if (isset($actionConfiguration['handle']) && $actionConfiguration['handle'] instanceof Closure) {
            $result = $actionConfiguration['handle']($request, $model, $payload);
        } elseif (in_array($action, ['create', 'update', 'delete'], true)) {
            $result = $this->defaultHandle($action, $request, $model, $payload, $configuration);
        } else {
            throw new HttpException(500, sprintf('No handler defined for action "%s".', $action));
        }

        if (in_array($action, ['create', 'update', 'delete'], true)) {
            $this->flushCache($modelClass, $configuration);
        }

        return $this->formatResult($result, $actionConfiguration, $request);
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    protected function resolveActionConfiguration(array $configuration, string $action): array
    {
        $actions = $configuration['actions'] ?? [];

        if (!is_array($actions) || !array_key_exists($action, $actions)) {
            throw new HttpException(405, 'The requested action is not allowed for this model.');
        }

        $actionConfiguration = $actions[$action];

        if ($actionConfiguration === null) {
            return [];
        }

        if (!is_array($actionConfiguration)) {
            throw new HttpException(500, 'The action configuration must be an array.');
        }

        return $actionConfiguration;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    protected function resolveModelInstance(
        string $action,
        Request $request,
        string $modelClass,
        array $configuration,
        ?string $identifier
    ): Model {
        /** @var Model $instance */
        $instance = app($modelClass);

        if ($action === 'create') {
            return $instance;
        }

        if (!in_array($action, ['update', 'delete'], true)) {
            return $instance;
        }

        if ($identifier === null || $identifier === '') {
            throw new HttpException(400, 'A valid identifier is required for this action.');
        }

        $builder = $this->applyBaseQuery(
            $instance->newQuery(),
            $configuration['query'] ?? null,
            $request
        );

        $model = $builder->where(
            $instance->getRouteKeyName(),
            $identifier
        )->first();

        if (!$model instanceof Model) {
            throw new HttpException(404, 'Model not found using the provided identifier.');
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request, array $actionConfiguration, string $action, bool $hasCustomHandler): array
    {
        $hasValidation = isset($actionConfiguration['validation']) && is_array($actionConfiguration['validation']);

        if (!$hasValidation) {
            $this->ensureValidationForDefaultActions($action, $hasCustomHandler);

            return $request->all();
        }

        return validator($request->all(), $actionConfiguration['validation'])->validate();
    }

    protected function ensureValidationForDefaultActions(string $action, bool $hasCustomHandler): void
    {
        if ($hasCustomHandler) {
            return;
        }

        if (in_array($action, ['create', 'update'], true)) {
            throw new HttpException(500, sprintf(
                'The "%s" action requires validation rules. Use ->validation([...]) to define the allowed fields.',
                $action
            ));
        }
    }

    protected function ensureAuthorized(Request $request, Model $model, array $actionConfiguration, string $action): void
    {
        if (isset($actionConfiguration['policy'])) {
            $abilities = $this->normalizePolicyAbilities($actionConfiguration['policy']);

            $gate = Gate::forUser($request->user());

            foreach ($abilities as $ability) {
                $subject = $action === 'create' ? $model::class : $model;

                try {
                    $gate->authorize($ability, $subject);
                } catch (AuthorizationException $exception) {
                    $message = $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : 'You are not authorized to perform this action.';

                    throw new HttpException(403, $message, $exception);
                }
            }

            return;
        }

        if (!isset($actionConfiguration['authorize'])) {
            return;
        }

        if (!$actionConfiguration['authorize'] instanceof Closure) {
            throw new HttpException(500, 'The authorize callback must be a closure.');
        }

        $result = $actionConfiguration['authorize']($request, $model);

        if ($result === false) {
            throw new HttpException(403, 'You are not authorized to perform this action.');
        }
    }

    /**
     * @param mixed $abilities
     * @return array<int, string>
     */
    protected function normalizePolicyAbilities($abilities): array
    {
        $abilities = is_array($abilities) ? $abilities : [$abilities];

        $normalized = [];

        foreach ($abilities as $ability) {
            if (!is_string($ability) || $ability === '') {
                throw new HttpException(500, 'Policy abilities must be non-empty strings.');
            }

            $normalized[] = $ability;
        }

        return $normalized;
    }

    protected function flushCache(string $modelClass, array $configuration): void
    {
        if (!isset($configuration['cache']['ttl'])) {
            return;
        }

        $name = $configuration['cache']['name'] ?? $modelClass;

        if (!is_string($name) || $name === '') {
            $name = $modelClass;
        }

        CacheRegistry::flush($name);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $configuration
     * @return mixed
     */
    protected function defaultHandle(string $action, Request $request, Model $model, array $payload, array $configuration)
    {
        switch ($action) {
            case 'create':
                $model->fill($payload);
                $model->save();

                return $this->applySelectColumns($model->refresh(), $configuration);
            case 'update':
                $model->fill($payload);
                $model->save();

                return $this->applySelectColumns($model, $configuration);
            case 'delete':
                $model->delete();

                return response()->noContent();
            default:
                return $model;
        }
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    protected function applySelectColumns(Model $model, array $configuration): array
    {
        $select = $configuration['select'] ?? null;

        if (!is_array($select) || $select === []) {
            return $model->toArray();
        }

        return $model->only($select);
    }

    /**
     * @param Closure|null $callback
     */
    protected function applyBaseQuery(Builder $builder, $callback, Request $request): Builder
    {
        if (!$callback instanceof Closure) {
            return $builder;
        }

        $result = $callback($builder, $request);

        if ($result instanceof Builder) {
            return $result;
        }

        return $builder;
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    protected function formatResult($result, array $actionConfiguration, Request $request)
    {
        if ($result instanceof SymfonyResponse) {
            return $result;
        }

        if ($result instanceof Responsable) {
            return $result->toResponse($request);
        }

        if (!isset($actionConfiguration['status'])) {
            return $result;
        }

        $status = (int) $actionConfiguration['status'];

        if ($request->expectsJson() || $request->wantsJson() || is_array($result) || $result instanceof Arrayable) {
            if ($result instanceof Arrayable) {
                $result = $result->toArray();
            }

            return response()->json($result, $status);
        }

        if (is_string($result) || is_numeric($result) || $result === null) {
            return response($result ?? '', $status);
        }

        return $result;
    }

    protected function ensureRequestMethod(Request $request, array $actionConfiguration, string $action): void
    {
        $expected = strtoupper($actionConfiguration['method'] ?? $this->defaultMethodFor($action));
        $actual = strtoupper($request->getMethod());

        if ($expected !== $actual) {
            throw new HttpException(405, sprintf(
                'Action "%s" must be invoked using the %s method.',
                $action,
                $expected
            ));
        }
    }

    protected function defaultMethodFor(string $action): string
    {
        $action = strtolower($action);

        return match ($action) {
            'create' => 'POST',
            'update' => 'PATCH',
            'delete' => 'DELETE',
            default => 'POST',
        };
    }
}

