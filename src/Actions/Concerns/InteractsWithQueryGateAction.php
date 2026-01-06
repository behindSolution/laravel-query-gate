<?php

namespace BehindSolution\LaravelQueryGate\Actions\Concerns;

use BehindSolution\LaravelQueryGate\Contracts\QueryGateAction;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use InvalidArgumentException;

trait InteractsWithQueryGateAction
{
    protected function makeActionInstance($action): QueryGateAction
    {
        if (is_string($action)) {
            $action = $this->container()->make($action);
        }

        if (!$action instanceof QueryGateAction) {
            throw new InvalidArgumentException('Query Gate actions must implement the QueryGateAction contract.');
        }

        return $action;
    }

    protected function container(): Container
    {
        return app();
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeActionConfiguration(QueryGateAction $action): array
    {
        $status = $action->status();

        if ($status !== null && (!is_int($status) || $status < 100 || $status > 599)) {
            throw new InvalidArgumentException('Status code must be an integer between 100 and 599.');
        }

        $configuration = [
            'name' => $this->normalizeName($action->name()),
            'validation' => $this->normalizeValidation($action->validations()),
            'policy' => $this->normalizePolicy($action->policy()),
            'authorize' => $this->buildAuthorizeCallback($action),
            'handle' => $this->buildHandleCallback($action),
            'method' => $this->normalizeMethod($action->method()),
            'status' => $status,
        ];

        return Arr::where($configuration, static function ($value) {
            return $value !== null && $value !== [];
        });
    }

    protected function buildAuthorizeCallback(QueryGateAction $action): ?callable
    {
        $authorize = $action->authorize(...);

        return static function ($request, $model) use ($authorize) {
            $result = $authorize($request, $model);

            return $result !== null ? (bool) $result : null;
        };
    }

    protected function buildHandleCallback(QueryGateAction $action): callable
    {
        return $action->handle(...);
    }

    protected function normalizeName(?string $name): ?string
    {
        $name = is_string($name) ? trim($name) : '';

        return $name === '' ? null : $name;
    }

    /**
     * @param array<string, mixed> $validation
     * @return array<string, mixed>|null
     */
    protected function normalizeValidation(array $validation): ?array
    {
        return $validation === [] ? null : $validation;
    }

    /**
     * @param array<int, string>|string|null $policy
     * @return array<int, string>|string|null
     */
    protected function normalizePolicy($policy)
    {
        if ($policy === null) {
            return null;
        }

        if (is_string($policy)) {
            $policy = trim($policy);

            return $policy !== '' ? $policy : null;
        }

        if (is_array($policy)) {
            $policy = array_values(array_filter($policy, static function ($ability) {
                return is_string($ability) && trim($ability) !== '';
            }));

            return $policy === [] ? null : $policy;
        }

        throw new InvalidArgumentException('Policy definition must be a string, array, or null.');
    }

    protected function normalizeMethod(string $method): string
    {
        $method = strtoupper(trim($method));

        if ($method === '') {
            throw new InvalidArgumentException('HTTP method cannot be empty.');
        }

        $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

        if (!in_array($method, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported HTTP method "%s" for Query Gate action. Allowed methods: %s.',
                $method,
                implode(', ', $allowed)
            ));
        }

        return $method;
    }
}
