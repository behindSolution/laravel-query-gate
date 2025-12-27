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

    public function toArray(): array
    {
        return array_filter([
            'validation' => $this->validation,
            'authorize' => $this->authorize,
            'policy' => $this->policies === [] ? null : $this->policies,
            'handle' => $this->handle,
        ], static function ($value) {
            return $value !== null;
        });
    }
}


