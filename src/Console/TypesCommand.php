<?php

namespace BehindSolution\LaravelQueryGate\Console;

use BehindSolution\LaravelQueryGate\Support\ModelRegistry;
use BehindSolution\LaravelQueryGate\TypeScript\TypesGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TypesCommand extends Command
{
    protected $signature = 'qg:types {--output=} {--api-version=}';

    protected $description = 'Generate TypeScript types for Query Gate resources.';

    public function handle(ModelRegistry $registry, TypesGenerator $generator): int
    {
        $outputDir = $this->resolveOutputDir();
        $requestedVersion = $this->option('api-version');

        $definitions = $registry->definitions();

        if (empty($definitions)) {
            $this->warn('No Query Gate models configured.');

            return self::SUCCESS;
        }

        if (!$this->ensureDirectoryExists($outputDir)) {
            return self::FAILURE;
        }

        $exports = [];

        foreach ($definitions as $modelClass => $definition) {
            $alias = $definition['alias'] ?? $this->classToAlias($modelClass);

            // Apply version if requested and available
            $resolvedDefinition = $this->resolveVersion($definition, $requestedVersion);
            $version = $this->determineVersion($definition, $requestedVersion);

            $content = $generator->generate($modelClass, $resolvedDefinition, $version);

            $filePath = "{$outputDir}/{$alias}.ts";

            if (file_put_contents($filePath, $content) === false) {
                $this->error(sprintf('Unable to write file [%s].', $filePath));

                return self::FAILURE;
            }

            $exports[] = $alias;
            $this->line("<info>Generated:</info> {$alias}.ts");
        }

        // Generate index.ts
        if (!$this->generateIndex($outputDir, $exports)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<info>Types generated in:</info> {$outputDir}");

        return self::SUCCESS;
    }

    /**
     * Resolve the output directory path.
     */
    protected function resolveOutputDir(): string
    {
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            return $output;
        }

        return storage_path('app/query-gate-types');
    }

    /**
     * Ensure the output directory exists.
     */
    protected function ensureDirectoryExists(string $directory): bool
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->error(sprintf('Unable to create directory [%s].', $directory));

            return false;
        }

        return true;
    }

    /**
     * Convert a model class name to an alias.
     */
    protected function classToAlias(string $class): string
    {
        $basename = class_basename($class);

        return Str::snake(Str::plural($basename));
    }

    /**
     * Resolve the definition with the requested version applied.
     *
     * @param array<string, mixed> $definition
     * @param string|null $requestedVersion
     * @return array<string, mixed>
     */
    protected function resolveVersion(array $definition, ?string $requestedVersion): array
    {
        $versions = $definition['versions'] ?? null;

        if (!is_array($versions) || empty($versions['definitions'])) {
            return $definition;
        }

        $versionDefinitions = $versions['definitions'];
        $versionOrder = $versions['order'] ?? array_keys($versionDefinitions);

        // Determine which version to apply
        if ($requestedVersion === null || $requestedVersion === 'latest') {
            // Use the default version (already applied by QueryGate::toArray())
            return $definition;
        }

        // Check if the requested version exists
        if (!isset($versionDefinitions[$requestedVersion])) {
            return $definition;
        }

        // Apply the requested version
        $versionDef = $versionDefinitions[$requestedVersion];

        foreach ($versionDef as $key => $value) {
            $definition[$key] = $value;
        }

        return $definition;
    }

    /**
     * Determine the version string to include in the generated output.
     *
     * @param array<string, mixed> $definition
     * @param string|null $requestedVersion
     * @return string|null
     */
    protected function determineVersion(array $definition, ?string $requestedVersion): ?string
    {
        $versions = $definition['versions'] ?? null;

        if (!is_array($versions) || empty($versions['definitions'])) {
            return null;
        }

        if ($requestedVersion !== null && $requestedVersion !== 'latest') {
            return isset($versions['definitions'][$requestedVersion]) ? $requestedVersion : null;
        }

        return $versions['default'] ?? null;
    }

    /**
     * Generate the index.ts file that re-exports all types.
     *
     * @param string $outputDir
     * @param array<int, string> $exports
     * @return bool
     */
    protected function generateIndex(string $outputDir, array $exports): bool
    {
        $lines = [];
        $lines[] = '// index.ts';
        $lines[] = '// Auto-generated by Laravel QueryGate - DO NOT EDIT';
        $lines[] = '';

        foreach ($exports as $alias) {
            $lines[] = "export * from './{$alias}'";
        }

        $lines[] = '';

        $content = implode("\n", $lines);
        $filePath = "{$outputDir}/index.ts";

        if (file_put_contents($filePath, $content) === false) {
            $this->error(sprintf('Unable to write file [%s].', $filePath));

            return false;
        }

        $this->line('<info>Generated:</info> index.ts');

        return true;
    }
}
