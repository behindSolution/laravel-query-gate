<?php

namespace BehindSolution\LaravelQueryGate\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ModelRegistry
{
    protected Repository $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        $models = $this->config->get('query-gate.models', []);

        if (!is_array($models)) {
            return [];
        }

        $resolved = [];

        foreach ($models as $key => $value) {
            [$class, $definition] = $this->resolveEntry($key, $value);
            $resolved[$class] = $definition;
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    public function aliasMap(): array
    {
        $aliases = [];

        foreach ($this->definitions() as $class => $definition) {
            $alias = $definition['alias'] ?? null;

            if (is_string($alias) && $alias !== '') {
                $aliases[strtolower($alias)] = $class;
            }
        }

        return $aliases;
    }

    /**
     * @return array<string, string>
     */
    public function slugMap(): array
    {
        $slugs = [];

        foreach ($this->definitions() as $class => $definition) {
            $alias = $definition['alias'] ?? null;

            if (is_string($alias) && $alias !== '') {
                $slugs[strtolower(Str::slug($alias, '-'))] = $class;
            }

            $slugs[strtolower(Str::slug($class, '-'))] = $class;
        }

        return $slugs;
    }

    /**
     * @param array-key $key
     * @param mixed $value
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function resolveEntry($key, $value): array
    {
        $class = $this->extractClass($key, $value);

        if (!is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException(sprintf(
                'The class [%s] must extend %s.',
                $class,
                Model::class
            ));
        }

        try {
            $definition = $this->normalizeDefinition($class, $value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(sprintf(
                'Invalid Query Gate configuration for [%s]: %s',
                $class,
                $exception->getMessage()
            ), 0, $exception);
        }

        return [$class, $definition];
    }

    /**
     * @param array-key $key
     * @param mixed $value
     */
    protected function extractClass($key, $value): string
    {
        if (is_string($key) && $key !== '') {
            return $key;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new InvalidArgumentException('Query Gate model definitions must reference a valid model class name.');
    }

    /**
     * @param mixed $source
     * @return array<string, mixed>
     */
    protected function normalizeDefinition(string $class, $source): array
    {
        if ($source instanceof QueryGate) {
            return $source->toArray();
        }

        if (is_string($source) && $source !== '') {
            return $this->resolveFromModel($source);
        }

        if ($source === null) {
            return $this->resolveFromModel($class);
        }

        throw new InvalidArgumentException(sprintf(
            'Query Gate definition for [%s] must be provided via QueryGate::make() or the HasQueryGate trait.',
            $class
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveFromModel(string $class): array
    {
        if (!method_exists($class, 'queryGate')) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must define a static queryGate() method. Use the HasQueryGate trait or provide a custom implementation.',
                $class
            ));
        }

        $definition = forward_static_call([$class, 'queryGate']);

        if (!$definition instanceof QueryGate) {
            throw new InvalidArgumentException(sprintf(
                'The queryGate() method on [%s] must return an instance of %s.',
                $class,
                QueryGate::class
            ));
        }

        return $definition->toArray();
    }
}


