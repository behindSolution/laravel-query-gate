<?php

namespace BehindSolution\LaravelQueryGate\Support;

use Illuminate\Contracts\Support\Arrayable;

class ActionsBuilder implements Arrayable
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $actions = [];

    public function create(?callable $callback = null): self
    {
        $this->actions['create'] = $this->buildAction($callback);

        return $this;
    }

    public function update(?callable $callback = null): self
    {
        $this->actions['update'] = $this->buildAction($callback);

        return $this;
    }

    public function delete(?callable $callback = null): self
    {
        $this->actions['delete'] = $this->buildAction($callback);

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
    protected function buildAction(?callable $callback): array
    {
        if ($callback === null) {
            return [];
        }

        $definition = new ActionDefinition();
        $result = $callback($definition);

        if ($result instanceof ActionDefinition) {
            $definition = $result;
        }

        return $definition->toArray();
    }
}


