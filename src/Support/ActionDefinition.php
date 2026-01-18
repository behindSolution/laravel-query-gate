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

    protected bool $withoutQuery = false;

    /**
     * @var array<string, mixed>
     */
    protected array $openapiRequestExamples = [];

    /**
     * @var array<int, string>|string|null
     */
    protected array|string|null $select = null;

    protected ?Closure $query = null;

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

    public function withoutQuery(): self
    {
        $this->withoutQuery = true;

        return $this;
    }

    /**
     * Set custom examples for OpenAPI request body documentation.
     *
     * @param array<string, mixed> $examples
     */
    public function openapiRequest(array $examples): self
    {
        $this->openapiRequestExamples = $examples;

        return $this;
    }

    /**
     * Set custom select columns or Resource class for this action.
     *
     * @param array<int, string>|string $columns
     */
    public function select(array|string $columns): self
    {
        $this->select = $columns;

        return $this;
    }

    /**
     * Set custom query callback for this action.
     */
    public function query(callable $callback): self
    {
        $this->query = $callback instanceof Closure
            ? $callback
            : Closure::fromCallable($callback);

        return $this;
    }

    public function toArray(): array
    {
        $data = array_filter([
            'validation' => $this->validation,
            'authorize' => $this->authorize,
            'policy' => $this->policies === [] ? null : $this->policies,
            'handle' => $this->handle,
            'status' => $this->status,
            'name' => $this->name,
            'method' => $this->method,
            'select' => $this->select,
            'query' => $this->query,
        ], static function ($value) {
            return $value !== null;
        });

        if ($this->withoutQuery) {
            $data['withoutQuery'] = true;
        }

        if ($this->openapiRequestExamples !== []) {
            $data['openapi_request'] = $this->openapiRequestExamples;
        }

        return $data;
    }
}


