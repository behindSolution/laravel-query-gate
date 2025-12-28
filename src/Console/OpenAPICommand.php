<?php

namespace BehindSolution\LaravelQueryGate\Console;

use BehindSolution\LaravelQueryGate\OpenApi\DocumentExtender;
use BehindSolution\LaravelQueryGate\OpenApi\OpenApiGenerator;
use Illuminate\Console\Command;
use JsonException;

class OpenAPICommand extends Command
{
    protected $signature = 'qg:openapi {--output=} {--format=}';

    protected $description = 'Generate an OpenAPI document representing the current Query Gate configuration.';

    public function handle(OpenApiGenerator $generator, DocumentExtender $extender): int
    {
        /** @var array<string, mixed>|null $config */
        $config = config('query-gate');

        if (!is_array($config)) {
            $this->error('The Query Gate configuration is not available.');

            return self::FAILURE;
        }

        $document = $extender->extend($generator->generate($config), $config);

        $format = strtolower((string) ($this->option('format') ?? $this->resolveOutputFormat($config)));

        if ($format === '') {
            $format = 'json';
        }

        $outputPath = $this->option('output') ?? $this->resolveOutputPath($config);

        if (!is_string($outputPath) || $outputPath === '') {
            $this->error('The output path must be provided.');

            return self::FAILURE;
        }

        $directory = dirname($outputPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->error(sprintf('Unable to create directory [%s].', $directory));

            return self::FAILURE;
        }

        try {
            $contents = $this->encodeDocument($document, $format);
        } catch (JsonException $exception) {
            $this->error('Failed to encode the OpenAPI document: ' . $exception->getMessage());

            return self::FAILURE;
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (file_put_contents($outputPath, $contents) === false) {
            $this->error(sprintf('Unable to write file [%s].', $outputPath));

            return self::FAILURE;
        }

        $this->line(sprintf(
            '<info>OpenAPI document generated:</info> %s (%s)',
            $outputPath,
            strtoupper($format)
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function resolveOutputPath(array $config): string
    {
        $path = $config['openAPI']['output']['path'] ?? storage_path('app/query-gate-openapi.json');

        return is_string($path) && $path !== ''
            ? $path
            : storage_path('app/query-gate-openapi.json');
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function resolveOutputFormat(array $config): string
    {
        $format = $config['openAPI']['output']['format'] ?? 'json';

        return is_string($format) ? strtolower($format) : 'json';
    }

    /**
     * @param array<string, mixed> $document
     * @throws JsonException
     */
    protected function encodeDocument(array $document, string $format): string
    {
        if ($format === 'yaml' || $format === 'yml') {
            if (!function_exists('yaml_emit')) {
                throw new \RuntimeException('The YAML extension is not available. Install ext-yaml or choose the JSON format.');
            }

            return yaml_emit($document, YAML_UTF8_ENCODING, YAML_LN_BREAK);
        }

        if ($format !== 'json') {
            throw new \RuntimeException('Unsupported format. Use json or yaml.');
        }

        return json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}


