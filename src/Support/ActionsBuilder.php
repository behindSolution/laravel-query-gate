<?php

namespace BehindSolution\LaravelQueryGate\Support;

use BehindSolution\LaravelQueryGate\Actions\Concerns\InteractsWithQueryGateAction;
use BehindSolution\LaravelQueryGate\Contracts\QueryGateAction;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

class ActionsBuilder implements Arrayable
{
    use InteractsWithQueryGateAction;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $actions = [];

    public function create(?callable $callback = null): self
    {
        $this->assignAction('create', $callback);

        return $this;
    }

    public function update(?callable $callback = null): self
    {
        $this->assignAction('update', $callback);

        return $this;
    }

    public function delete(?callable $callback = null): self
    {
        $this->assignAction('delete', $callback);

        return $this;
    }

    public function use(string $actionClass): self
    {
        $instance = $this->makeActionInstance($actionClass);

        $action = $instance->action();

        if (!is_string($action) || trim($action) === '') {
            throw new InvalidArgumentException('Query Gate action classes must define a non-empty action name.');
        }

        $configuration = $this->normalizeActionConfiguration($instance);
        $configuration['class'] = get_class($instance);
        $configuration['method'] = $configuration['method'] ?? $this->defaultMethodFor($action);

        $this->mergeAction($action, $configuration);

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->actions;
    }

    /**
     * @return array<string, mixed>
     */
    protected function assignAction(string $action, ?callable $callback): void
    {
        $action = trim($action);

        if ($action === '') {
            throw new InvalidArgumentException('Action name cannot be empty.');
        }

        if ($callback === null) {
            $this->actions[$action] = $this->actions[$action] ?? [];
            $this->actions[$action]['method'] = $this->actions[$action]['method'] ?? $this->defaultMethodFor($action);

            return;
        }

        $definition = new ActionDefinition();
        $result = $callback($definition);

        if ($result instanceof ActionDefinition) {
            $definition = $result;
        }

        $configuration = $definition->toArray();

        if (!isset($configuration['method'])) {
            $configuration['method'] = $this->defaultMethodFor($action);
        }

        $this->mergeAction($action, $configuration);
    }

    protected function mergeAction(string $action, array $configuration): void
    {
        $action = trim($action);

        if ($action === '') {
            throw new InvalidArgumentException('Action name cannot be empty.');
        }

        if (isset($configuration['method'])) {
            $configuration['method'] = $this->normalizeMethod($configuration['method']);
        }

        $current = $this->actions[$action] ?? ['method' => $this->defaultMethodFor($action)];

        $this->actions[$action] = array_merge($current, $configuration);
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


