<?php

namespace BehindSolution\LaravelQueryGate\OpenApi;

use BehindSolution\LaravelQueryGate\Contracts\OpenApi\DocumentModifier;
use Closure;
use Illuminate\Contracts\Container\Container;

class DocumentExtender
{
    protected Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function extend(array $document, array $config): array
    {
        $modifiers = $config['swagger']['modifiers'] ?? [];

        if (!is_array($modifiers) || $modifiers === []) {
            return $document;
        }

        foreach ($modifiers as $modifier) {
            $document = $this->applyModifier($document, $modifier);

            if (!is_array($document)) {
                $document = [];
                break;
            }
        }

        return $document;
    }

    /**
     * @param mixed $modifier
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    protected function applyModifier(array $document, $modifier): array
    {
        if ($modifier instanceof DocumentModifier) {
            return $this->ensureArray($modifier->modify($document));
        }

        if ($modifier instanceof Closure) {
            return $this->ensureCallableResult($modifier, $document);
        }

        if (is_string($modifier) && class_exists($modifier)) {
            $instance = $this->container->make($modifier);

            if ($instance instanceof DocumentModifier) {
                return $this->ensureArray($instance->modify($document));
            }

            if (is_callable($instance)) {
                return $this->ensureCallableResult($instance, $document);
            }
        }

        if (is_callable($modifier)) {
            return $this->ensureCallableResult($modifier, $document);
        }

        return $document;
    }

    /**
     * @param callable $callable
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    protected function ensureCallableResult(callable $callable, array $document): array
    {
        $result = $callable($document);

        return $this->ensureArray($result);
    }

    /**
     * @param mixed $result
     * @return array<string, mixed>
     */
    protected function ensureArray($result): array
    {
        return is_array($result) ? $result : [];
    }
}


