<?php

namespace BehindSolution\LaravelQueryGate\Support;

use Closure;
use Illuminate\Contracts\Support\Arrayable;

class ActionDefinition implements Arrayable
{
    protected ?array $validation = null;

    protected ?Closure $authorize = null;

    /**
     * @var array<int, string>
     */
    protected array $policies = [];

    protected ?Closure $handle = null;

    protected ?int $status = null;

    protected ?string $name = null;

    protected ?string $method = null;

    public function validations(array $rules): self
    {
        $this->validation = $rules;

        return $this;
    }

    public function validation(array $rules): self
    {
        return $this->validations($rules);
    }

    public function authorize(callable $callback): self
    {
        $this->authorize = $callback instanceof Closure
            ? $callback
            : Closure::fromCallable($callback);

        return $this;
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function policy($abilities): self
    {
        $abilities = is_array($abilities) ? $abilities : [$abilities];

        $this->policies = array_values(array_filter(array_map(static function ($ability) {
            return is_string($ability) && $ability !== '' ? $ability : null;
        }, $abilities)));

        return $this;
    }

    public function handle(callable $callback): self
    {
        $this->handle = $callback instanceof Closure
            ? $callback
            : Closure::fromCallable($callback);

        return $this;
    }

    public function status(?int $status): self
    {
        if ($status !== null && ($status < 100 || $status > 599)) {
            throw new \InvalidArgumentException('Status code must be between 100 and 599.');
        }

        $this->status = $status;

        return $this;
    }

    public function method(string $method): self
    {
        $method = strtoupper(trim($method));

        if ($method === '') {
            throw new \InvalidArgumentException('HTTP method cannot be empty.');
        }

        $this->method = $method;

        return $this;
    }

    public function name(?string $name): self
    {
        $this->name = $name !== null && $name !== '' ? $name : null;

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'validation' => $this->validation,
            'authorize' => $this->authorize,
            'policy' => $this->policies === [] ? null : $this->policies,
            'handle' => $this->handle,
            'status' => $this->status,
            'name' => $this->name,
            'method' => $this->method,
        ], static function ($value) {
            return $value !== null;
        });
    }
}


