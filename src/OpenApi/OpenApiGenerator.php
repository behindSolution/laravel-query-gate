<?php

namespace BehindSolution\LaravelQueryGate\OpenApi;

use BehindSolution\LaravelQueryGate\Support\QueryGate;
use Illuminate\Support\Str;

class OpenApiGenerator
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function generate(array $config): array
    {
        $openApiConfig = is_array($config['openAPI'] ?? null) ? $config['openAPI'] : [];
        $models = $this->resolveModels($config);

        $document = [
            'openapi' => '3.1.0',
            'info' => $this->buildInfo($openApiConfig),
            'servers' => $this->buildServers($openApiConfig),
            'tags' => $this->buildTags($openApiConfig, $models),
            'paths' => $this->buildPaths($config, $models, $openApiConfig),
            'components' => $this->buildComponents($models, $openApiConfig),
            'security' => $this->buildSecurityRequirement($openApiConfig),
            'x-query-gate' => [
                'models' => array_values($models),
            ],
        ];

        return $this->removeEmptyValues($document);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array<string, mixed>>
     */
    protected function resolveModels(array $config): array
    {
        $definitions = [];
        $aliases = $this->invertAliases($config['model_aliases'] ?? []);
        $models = $config['models'] ?? [];

        if (!is_array($models)) {
            return [];
        }

        foreach ($models as $modelClass => $definition) {
            if (!is_string($modelClass) || $modelClass === '') {
                continue;
            }

            if ($definition instanceof QueryGate) {
                $definition = $definition->toArray();
            }

            if (!is_array($definition)) {
                $definition = [];
            }

            $component = $this->buildComponentName($modelClass);

            $definitions[$modelClass] = array_merge(
                [
                    'model' => $modelClass,
                    'aliases' => $aliases[$modelClass] ?? [],
                    'component' => $component,
                    'definition' => '#/components/schemas/' . $component . 'Definition',
                ],
                $this->sanitizeDefinition($definition)
            );
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function sanitizeDefinition(array $definition): array
    {
        $filters = $this->sanitizeFilters($definition['filters'] ?? []);
        $operators = is_array($definition['filter_operators'] ?? null) ? $definition['filter_operators'] : [];
        $rawFilters = is_array($definition['raw_filters'] ?? null) ? array_keys($definition['raw_filters']) : [];

        return [
            'has_query_callback' => isset($definition['query']),
            'middleware' => $this->normalizeStringArray($definition['middleware'] ?? []),
            'pagination' => $this->sanitizePagination($definition['pagination'] ?? []),
            'cache' => $this->sanitizeCache($definition['cache'] ?? null),
            'filters' => $this->mergeFilterMetadata($filters, $operators, $rawFilters),
            'select' => $this->normalizeStringArray($definition['select'] ?? []),
            'actions' => $this->sanitizeActions($definition['actions'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $pagination
     * @return array<string, mixed>|null
     */
    protected function sanitizePagination($pagination): ?array
    {
        if (!is_array($pagination) || !isset($pagination['mode'])) {
            return null;
        }

        $mode = is_string($pagination['mode']) ? strtolower($pagination['mode']) : null;

        if (!in_array($mode, ['classic', 'cursor', 'none'], true)) {
            $mode = null;
        }

        if ($mode === null) {
            return null;
        }

        $result = ['mode' => $mode];

        if (isset($pagination['per_page']) && is_numeric($pagination['per_page'])) {
            $result['per_page'] = (int) $pagination['per_page'];
        }

        if (isset($pagination['cursor']) && is_string($pagination['cursor'])) {
            $result['cursor'] = $pagination['cursor'];
        }

        return $result;
    }

    /**
     * @param mixed $cache
     * @return array<string, mixed>|null
     */
    protected function sanitizeCache($cache): ?array
    {
        if (!is_array($cache) || !isset($cache['ttl'])) {
            return null;
        }

        $ttl = (int) $cache['ttl'];

        if ($ttl <= 0) {
            return null;
        }

        $name = $cache['name'] ?? null;

        return [
            'ttl' => $ttl,
            'name' => is_string($name) && $name !== '' ? $name : null,
        ];
    }

    /**
     * @param array<string, mixed> $definitions
     * @return array<string, array<string, mixed>>
     */
    protected function sanitizeFilters($definitions): array
    {
        $filters = [];

        if (!is_array($definitions)) {
            return $filters;
        }

        foreach ($definitions as $field => $rules) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $filters[$field] = [
                'rules' => $this->normalizeRules($rules),
            ];
        }

        return $filters;
    }

    /**
     * @param array<string, array<int, string>> $filters
     * @param array<string, mixed> $operators
     * @param array<int, string> $rawFilters
     * @return array<string, array<string, mixed>>
     */
    protected function mergeFilterMetadata(array $filters, array $operators, array $rawFilters): array
    {
        foreach ($operators as $field => $values) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            if (!isset($filters[$field])) {
                $filters[$field] = [
                    'rules' => [],
                ];
            }

            $filters[$field]['operators'] = $this->normalizeStringArray($values, true);
        }

        foreach ($rawFilters as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            if (!isset($filters[$field])) {
                $filters[$field] = [
                    'rules' => [],
                ];
            }

            $filters[$field]['uses_raw_callback'] = true;
        }

        ksort($filters);

        return $filters;
    }

    /**
     * @param array<string, mixed> $definitions
     * @return array<string, array<string, mixed>>
     */
    protected function sanitizeActions($definitions): array
    {
        $actions = [];

        if (!is_array($definitions)) {
            return $actions;
        }

        foreach (['create', 'update', 'delete'] as $action) {
            if (!array_key_exists($action, $definitions)) {
                continue;
            }

            $configuration = $definitions[$action];

            if ($configuration === null) {
                $configuration = [];
            }

            if (!is_array($configuration)) {
                $configuration = [];
            }

            $validation = $this->buildValidationSchema($configuration['validation'] ?? []);

            $actions[$action] = [
                'enabled' => true,
                'policies' => $this->normalizeStringArray($configuration['policy'] ?? []),
                'uses_authorize_callback' => array_key_exists('authorize', $configuration),
                'uses_handle_callback' => array_key_exists('handle', $configuration),
                'validation' => $validation,
            ];
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    protected function buildValidationSchema($validation): array
    {
        if (!is_array($validation) || $validation === []) {
            return [
                'fields' => [],
                'schema' => null,
            ];
        }

        $fields = [];
        $properties = [];
        $required = [];

        foreach ($validation as $field => $rules) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $normalizedRules = $this->normalizeRules($rules);

            if ($normalizedRules === []) {
                continue;
            }

            $fields[$field] = $normalizedRules;

            $property = $this->buildPropertyFromRules($normalizedRules);

            if ($property !== []) {
                $properties[$field] = $property;
            }

            if (in_array('required', $normalizedRules, true)) {
                $required[] = $field;
            }
        }

        $schema = [];

        if ($properties !== []) {
            $schema['type'] = 'object';
            $schema['properties'] = $properties;

            if ($required !== []) {
                $schema['required'] = array_values(array_unique($required));
            }

            $schema['additionalProperties'] = false;
        }

        return [
            'fields' => $fields,
            'schema' => $schema === [] ? null : $schema,
        ];
    }

    /**
     * @param array<int, string> $rules
     * @return array<string, mixed>
     */
    protected function buildPropertyFromRules(array $rules): array
    {
        $property = [
            'type' => 'string',
        ];

        $lowerRules = array_map('strtolower', $rules);

        if ($this->containsRule($lowerRules, 'array')) {
            $property['type'] = 'array';
            $property['items'] = ['type' => 'string'];
        } elseif ($this->containsRule($lowerRules, 'boolean')) {
            $property['type'] = 'boolean';
        } elseif ($this->containsRule($lowerRules, 'integer')) {
            $property['type'] = 'integer';
        } elseif ($this->containsRule($lowerRules, 'numeric')) {
            $property['type'] = 'number';
        } elseif ($this->containsRule($lowerRules, 'date')) {
            $property['type'] = 'string';
            $property['format'] = 'date';
        } elseif ($this->containsRule($lowerRules, 'datetime')) {
            $property['type'] = 'string';
            $property['format'] = 'date-time';
        } else {
            $property['type'] = 'string';
        }

        if ($this->containsRule($lowerRules, 'email')) {
            $property['format'] = 'email';
        }

        if ($this->containsRule($lowerRules, 'uuid')) {
            $property['format'] = 'uuid';
        }

        if ($this->containsRule($lowerRules, 'nullable')) {
            $property['nullable'] = true;
        }

        $property['description'] = 'Rules: ' . implode(', ', $rules);

        return $property;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function buildInfo(array $config): array
    {
        return array_filter([
            'title' => is_string($config['title'] ?? null) ? $config['title'] : 'Query Gate API',
            'description' => is_string($config['description'] ?? null)
                ? $config['description']
                : 'Generated documentation for Query Gate endpoints.',
            'version' => is_string($config['version'] ?? null) ? $config['version'] : '1.0.0',
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    protected function buildServers(array $config): array
    {
        $servers = $config['servers'] ?? [];

        if (!is_array($servers)) {
            return [];
        }

        $result = [];

        foreach ($servers as $server) {
            if (is_string($server) && $server !== '') {
                $result[] = ['url' => $server];
                continue;
            }

            if (is_array($server) && isset($server['url']) && is_string($server['url']) && $server['url'] !== '') {
                $result[] = array_filter([
                    'url' => $server['url'],
                    'description' => is_string($server['description'] ?? null) ? $server['description'] : null,
                ], static fn ($value) => $value !== null);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    protected function buildTags(array $config, array $models): array
    {
        $tags = $config['tags'] ?? [];

        $result = [];

        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $result[] = [
                        'name' => $tag,
                    ];

                    continue;
                }

                if (is_array($tag) && isset($tag['name']) && is_string($tag['name']) && $tag['name'] !== '') {
                    $result[] = array_filter([
                        'name' => $tag['name'],
                        'description' => is_string($tag['description'] ?? null) ? $tag['description'] : null,
                    ], static fn ($value) => $value !== null);
                }
            }
        }

        $existing = array_map(static function ($tag) {
            return strtolower($tag['name']);
        }, $result);

        foreach ($models as $model) {
            $tagName = $this->resolveModelTagName($model);

            if (!in_array(strtolower($tagName), $existing, true)) {
                $result[] = array_filter([
                    'name' => $tagName,
                    'description' => 'Operations for ' . $model['model'],
                ], static fn ($value) => $value !== null);

                $existing[] = strtolower($tagName);
            }
        }

        if ($result === []) {
            return [
                [
                    'name' => 'Query Gate',
                    'description' => 'Endpoints powered by Laravel Query Gate.',
                ],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $rootConfig
     * @param array<string, array<string, mixed>> $models
     * @param array<string, mixed> $openApiConfig
     * @return array<string, mixed>
     */
    protected function buildPaths(array $rootConfig, array $models, array $openApiConfig): array
    {
        $prefix = $rootConfig['route']['prefix'] ?? 'query';
        $prefix = is_string($prefix) ? trim($prefix, '/') : 'query';
        $basePath = '/' . ($prefix === '' ? '' : $prefix);
        if ($basePath === '//') {
            $basePath = '/';
        }

        $paths = [];

        foreach ($models as $model) {
            $tag = $this->resolveModelTagName($model);
            $listPath = $this->buildModelListPath($basePath, $model);
            $paths[$listPath] = array_filter([
                'parameters' => [
                    $this->buildModelParameter($model),
                ],
                'get' => $this->buildModelIndexOperation($model, $tag, $openApiConfig, $basePath),
                'post' => $this->modelHasAction($model, 'create')
                    ? $this->buildModelActionOperation('create', $model, $tag, $openApiConfig, false, $basePath)
                    : null,
            ]);

            if ($this->modelHasAction($model, 'update') || $this->modelHasAction($model, 'delete')) {
                $detailPath = $this->buildModelDetailPath($listPath);

                $parameters = [
                    $this->buildModelParameter($model),
                    $this->buildIdentifierParameter(),
                ];

                $paths[$detailPath] = array_filter([
                    'parameters' => $parameters,
                    'patch' => $this->modelHasAction($model, 'update')
                        ? $this->buildModelActionOperation('update', $model, $tag, $openApiConfig, true, $basePath . '/{id}')
                        : null,
                    'delete' => $this->modelHasAction($model, 'delete')
                        ? $this->buildModelDeleteOperation($model, $tag, $openApiConfig, $basePath . '/{id}')
                        : null,
                ]);
            }
        }

        return $paths;
    }

    protected function buildModelListPath(string $basePath, array $model): string
    {
        $slug = $this->modelSlug($model);

        if ($basePath === '/') {
            return '/' . $slug;
        }

        return rtrim($basePath, '/') . '/' . $slug;
    }

    protected function buildModelDetailPath(string $listPath): string
    {
        return rtrim($listPath, '/') . '/{id}';
    }

    protected function modelHasAction(array $model, string $action): bool
    {
        return isset($model['actions'][$action]);
    }

    protected function buildModelIndexOperation(array $model, string $tag, array $openApiConfig, string $originalPath): array
    {
        $plural = $this->resolveModelPluralName($model);

        return $this->removeEmptyValues([
            'summary' => 'List ' . $plural,
            'description' => 'Returns ' . strtolower($plural) . ' applying Query Gate filters, sorting, selection, and pagination rules.',
            'tags' => [$tag],
            'parameters' => [
                $this->buildFilterParameter(),
                $this->buildSortParameter(),
                $this->buildCursorParameter(),
            ],
            'responses' => [
                '200' => [
                    'description' => 'Successful response.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'description' => 'The shape depends on the selected model and pagination mode.',
                            ],
                        ],
                    ],
                ],
            ],
            'security' => $this->buildSecurityRequirement($openApiConfig),
            'x-query-gate' => [
                'model' => $model['model'],
                'aliases' => $model['aliases'],
                'definition' => '#/components/schemas/' . $model['component'] . 'Definition',
                'original_path' => $originalPath,
            ],
        ]);
    }

    protected function buildModelActionOperation(
        string $action,
        array $model,
        string $tag,
        array $openApiConfig,
        bool $withIdentifier,
        string $originalPath
    ): array {
        $singular = $this->resolveModelSingularName($model);

        return $this->removeEmptyValues([
            'summary' => ucfirst($action) . ' ' . $singular,
            'description' => 'Executes the "' . $action . '" action for ' . strtolower($singular) . ' via Query Gate.',
            'tags' => [$tag],
            'requestBody' => $this->buildModelRequestBody($model, $action),
            'responses' => $this->buildActionResponses($action),
            'security' => $this->buildSecurityRequirement($openApiConfig),
            'x-query-gate' => [
                'model' => $model['model'],
                'aliases' => $model['aliases'],
                'definition' => '#/components/schemas/' . $model['component'] . 'Definition',
                'original_path' => $originalPath,
                'requires_identifier' => $withIdentifier,
            ],
        ]);
    }

    protected function buildModelDeleteOperation(array $model, string $tag, array $openApiConfig, string $originalPath): array
    {
        $singular = $this->resolveModelSingularName($model);

        return $this->removeEmptyValues([
            'summary' => 'Delete ' . $singular,
            'description' => 'Executes the "delete" action for ' . strtolower($singular) . ' via Query Gate.',
            'tags' => [$tag],
            'responses' => [
                '204' => [
                    'description' => 'Resource deleted successfully.',
                ],
            ],
            'security' => $this->buildSecurityRequirement($openApiConfig),
            'x-query-gate' => [
                'model' => $model['model'],
                'aliases' => $model['aliases'],
                'definition' => '#/components/schemas/' . $model['component'] . 'Definition',
                'original_path' => $originalPath,
                'requires_identifier' => true,
            ],
        ]);
    }

    protected function buildModelRequestBody(array $model, string $action): ?array
    {
        return $this->buildRequestBody([$model], $action);
    }

    protected function buildModelParameter(array $model): array
    {
        $identifiers = array_values(array_unique(array_filter(array_merge(
            $model['aliases'],
            [$model['model']]
        ), static function ($value) {
            return is_string($value) && $value !== '';
        })));

        $default = $identifiers[0] ?? $model['model'];

        return [
            'name' => 'model',
            'in' => 'query',
            'required' => true,
            'description' => 'Fixed model identifier for this endpoint.',
            'schema' => array_filter([
                'type' => 'string',
                'enum' => $identifiers,
                'default' => $default,
            ], static fn ($value) => $value !== null),
            'example' => $default,
            'x-query-gate-model' => $model['model'],
        ];
    }

    protected function modelSlug(array $model): string
    {
        if (!empty($model['aliases'])) {
            $slug = Str::slug($model['aliases'][0]);

            if ($slug !== '') {
                return $slug;
            }
        }

        $class = class_basename($model['model']);
        $slug = Str::slug($class);

        if ($slug !== '') {
            return $slug;
        }

        return 'model-' . substr(md5($model['model']), 0, 8);
    }

    protected function resolveModelTagName(array $model): string
    {
        return $this->resolveModelPluralName($model);
    }

    protected function resolveModelSingularName(array $model): string
    {
        $base = $this->resolveModelBaseName($model);

        return Str::title(Str::singular($base));
    }

    protected function resolveModelPluralName(array $model): string
    {
        $base = $this->resolveModelBaseName($model);

        return Str::title(Str::plural($base));
    }

    protected function resolveModelBaseName(array $model): string
    {
        if (!empty($model['aliases'])) {
            return str_replace(['-', '_'], ' ', strtolower($model['aliases'][0]));
        }

        $class = class_basename($model['model']);
        $snake = Str::snake($class);

        return str_replace('_', ' ', strtolower($snake));
    }

    protected function buildFilterParameter(): array
    {
        return [
            'name' => 'filter',
            'in' => 'query',
            'required' => false,
            'description' => 'Filter definitions using the format filter[field][operator]=value. See x-query-gate metadata for allowed fields and operators.',
            'style' => 'deepObject',
            'explode' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
        ];
    }

    protected function buildSortParameter(): array
    {
        return [
            'name' => 'sort',
            'in' => 'query',
            'required' => false,
            'description' => 'Comma-separated sort instructions (e.g. created_at:desc,id:asc).',
            'schema' => [
                'type' => 'string',
            ],
        ];
    }

    protected function buildCursorParameter(): array
    {
        return [
            'name' => 'cursor',
            'in' => 'query',
            'required' => false,
            'description' => 'Cursor token for cursor-based pagination.',
            'schema' => [
                'type' => 'string',
            ],
        ];
    }

    protected function buildIdentifierParameter(): array
    {
        return [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'Model identifier (uses the route key name).',
            'schema' => [
                'type' => 'string',
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $models
     * @return array<string, mixed>|null
     */
    protected function buildRequestBody(array $models, string $action): ?array
    {
        $schemas = [];

        foreach ($models as $model) {
            if (!isset($model['actions'][$action])) {
                continue;
            }

            $schemas[] = '#/components/schemas/' . $model['component'] . ucfirst($action) . 'Request';
        }

        if ($schemas === []) {
            return null;
        }

        $contentSchema = count($schemas) === 1
            ? ['$ref' => $schemas[0]]
            : ['oneOf' => array_map(static function ($ref) {
                return ['$ref' => $ref];
            }, $schemas)];

        return [
            'required' => in_array($action, ['create', 'update'], true),
            'content' => [
                'application/json' => [
                    'schema' => $contentSchema,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function buildActionResponses(string $action): array
    {
        if ($action === 'create') {
            return [
                '201' => [
                    'description' => 'Resource created successfully.',
                ],
            ];
        }

        return [
            '200' => [
                'description' => 'Action executed successfully.',
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $models
     * @param array<string, mixed> $openApiConfig
     * @return array<string, mixed>
     */
    protected function buildComponents(array $models, array $openApiConfig): array
    {
        $schemas = [];

        foreach ($models as $model) {
            $schemas[$model['component'] . 'Definition'] = $this->buildModelDefinitionSchema($model);

            foreach ($model['actions'] as $action => $actionData) {
                $schemas[$model['component'] . ucfirst($action) . 'Request'] = $this->buildActionRequestSchema(
                    $model,
                    $action,
                    $actionData
                );
            }
        }

        $components = [
            'schemas' => $schemas,
        ];

        $securityScheme = $this->buildSecurityScheme($openApiConfig);

        if ($securityScheme !== null) {
            $components['securitySchemes'] = [
                'QueryGateAuth' => $securityScheme,
            ];
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    protected function buildModelDefinitionSchema(array $model): array
    {
        $filterProperties = [];

        foreach ($model['filters'] as $field => $metadata) {
            $filterProperties[$field] = array_filter([
                'type' => 'object',
                'properties' => array_filter([
                    'rules' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'operators' => isset($metadata['operators'])
                        ? [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ]
                        : null,
                    'uses_raw_callback' => isset($metadata['uses_raw_callback']) ? [
                        'type' => 'boolean',
                    ] : null,
                ]),
                'x-query-gate-rules' => $metadata['rules'] ?? [],
                'x-query-gate-operators' => $metadata['operators'] ?? [],
                'x-query-gate-uses-raw' => $metadata['uses_raw_callback'] ?? false,
            ], static fn ($value) => $value !== null);
        }

        $actions = [];
        foreach ($model['actions'] as $name => $action) {
            $actions[$name] = array_filter([
                'type' => 'object',
                'properties' => [
                    'policies' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'uses_authorize_callback' => [
                        'type' => 'boolean',
                    ],
                    'uses_handle_callback' => [
                        'type' => 'boolean',
                    ],
                ],
                'x-query-gate-validation' => $action['validation']['fields'] ?? [],
                'x-query-gate-policies' => $action['policies'] ?? [],
                'x-query-gate-custom-authorize' => $action['uses_authorize_callback'] ?? false,
                'x-query-gate-custom-handle' => $action['uses_handle_callback'] ?? false,
            ]);
        }

        return array_filter([
            'type' => 'object',
            'description' => 'Configuration summary for ' . $model['model'],
            'properties' => array_filter([
                'model' => [
                    'type' => 'string',
                    'example' => $model['model'],
                ],
                'aliases' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'example' => $model['aliases'],
                ],
                'middleware' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'pagination' => $model['pagination'] !== null ? [
                    'type' => 'object',
                    'properties' => [
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['classic', 'cursor', 'none'],
                            'example' => $model['pagination']['mode'],
                        ],
                        'per_page' => [
                            'type' => 'integer',
                            'nullable' => true,
                        ],
                        'cursor' => [
                            'type' => 'string',
                            'nullable' => true,
                        ],
                    ],
                ] : null,
                'cache' => $model['cache'] !== null ? [
                    'type' => 'object',
                    'properties' => [
                        'ttl' => ['type' => 'integer'],
                        'name' => ['type' => 'string', 'nullable' => true],
                    ],
                ] : null,
                'select' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'filters' => $filterProperties !== [] ? [
                    'type' => 'object',
                    'properties' => $filterProperties,
                ] : null,
                'actions' => $actions !== [] ? [
                    'type' => 'object',
                    'properties' => $actions,
                ] : null,
            ]),
            'x-query-gate' => [
                'model' => $model['model'],
                'aliases' => $model['aliases'],
                'middleware' => $model['middleware'],
                'pagination' => $model['pagination'],
                'cache' => $model['cache'],
                'filters' => $model['filters'],
                'select' => $model['select'],
                'actions' => $model['actions'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $model
     * @param string $action
     * @param array<string, mixed> $actionData
     * @return array<string, mixed>
     */
    protected function buildActionRequestSchema(array $model, string $action, array $actionData): array
    {
        $schema = $actionData['validation']['schema'] ?? null;

        if (!is_array($schema) || $schema === []) {
            $schema = [
                'type' => 'object',
                'additionalProperties' => true,
                'description' => 'Payload accepted by the action. Validation rules are defined by the host application.',
            ];
        } else {
            $schema['description'] = 'Payload accepted by the action. Rules enforced by the host application.';
        }

        $schema['x-query-gate-rules'] = $actionData['validation']['fields'] ?? [];
        $schema['x-query-gate-policies'] = $actionData['policies'] ?? [];
        $schema['x-query-gate-custom-authorize'] = $actionData['uses_authorize_callback'] ?? false;
        $schema['x-query-gate-custom-handle'] = $actionData['uses_handle_callback'] ?? false;
        $schema['x-query-gate-model'] = $model['model'];
        $schema['x-query-gate-action'] = $action;

        return $schema;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array<string, string>>
     */
    protected function buildSecurityRequirement(array $config): array
    {
        $securityScheme = $this->buildSecurityScheme($config);

        if ($securityScheme === null) {
            return [];
        }

        return [
            ['QueryGateAuth' => []],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    protected function buildSecurityScheme(array $config): ?array
    {
        $auth = $config['auth'] ?? null;

        if (!is_array($auth) || !isset($auth['type'])) {
            return null;
        }

        $type = is_string($auth['type']) ? strtolower($auth['type']) : null;

        if ($type === null || $type === '') {
            return null;
        }

        if ($type === 'http') {
            $scheme = is_string($auth['scheme'] ?? null) ? strtolower($auth['scheme']) : 'bearer';

            $definition = [
                'type' => 'http',
                'scheme' => $scheme,
            ];

            if ($scheme === 'bearer' && isset($auth['bearer_format']) && is_string($auth['bearer_format'])) {
                $definition['bearerFormat'] = $auth['bearer_format'];
            }

            return $definition;
        }

        if ($type === 'apikey' || $type === 'api_key') {
            $name = is_string($auth['name'] ?? null) ? $auth['name'] : null;
            $location = is_string($auth['in'] ?? null) ? strtolower($auth['in']) : 'header';

            if ($name === null || $name === '') {
                return null;
            }

            if (!in_array($location, ['header', 'query', 'cookie'], true)) {
                $location = 'header';
            }

            return [
                'type' => 'apiKey',
                'name' => $name,
                'in' => $location,
            ];
        }

        if ($type === 'oauth2' && isset($auth['flows']) && is_array($auth['flows'])) {
            return [
                'type' => 'oauth2',
                'flows' => $auth['flows'],
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<int, string>
     */
    protected function normalizeRules($rules): array
    {
        if (is_string($rules)) {
            $rules = [$rules];
        }

        if (!is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $parts = array_map('trim', explode('|', $rule));

                foreach ($parts as $part) {
                    if ($part !== '') {
                        $normalized[] = $part;
                    }
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    protected function normalizeStringArray($values, bool $lowercase = false): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (!is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $normalized[] = $lowercase ? strtolower($value) : $value;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, string> $rules
     */
    protected function containsRule(array $rules, string $rule): bool
    {
        foreach ($rules as $item) {
            if (strpos($item, $rule) === 0) {
                return true;
            }
        }

        return in_array($rule, $rules, true);
    }

    /**
     * @param array<string, string> $aliases
     * @return array<string, array<int, string>>
     */
    protected function invertAliases($aliases): array
    {
        $normalized = [];

        if (!is_array($aliases)) {
            return $normalized;
        }

        foreach ($aliases as $alias => $class) {
            if (!is_string($alias) || $alias === '' || !is_string($class) || $class === '') {
                continue;
            }

            $normalized[$class][] = $alias;
        }

        foreach ($normalized as $class => $list) {
            $normalized[$class] = array_values(array_unique($list));
        }

        return $normalized;
    }

    protected function buildComponentName(string $modelClass): string
    {
        $normalized = str_replace('\\', '', $modelClass);
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $normalized);

        if ($normalized === '') {
            $normalized = 'Model' . substr(md5($modelClass), 0, 8);
        }

        return 'QueryGate' . $normalized;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected function removeEmptyValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->removeEmptyValues($value);
            }

            if ($values[$key] === [] || $values[$key] === null) {
                unset($values[$key]);
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $openApiConfig
     */
    protected function resolvePrimaryTag(array $openApiConfig): string
    {
        $tags = $this->buildTags($openApiConfig);

        if ($tags === []) {
            return 'Query Gate';
        }

        return $tags[0]['name'] ?? 'Query Gate';
    }
}


