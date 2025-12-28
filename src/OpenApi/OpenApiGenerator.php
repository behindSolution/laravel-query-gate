<?php

namespace BehindSolution\LaravelQueryGate\OpenApi;

use BehindSolution\LaravelQueryGate\Support\FilterParser;
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
            'sorts' => $this->normalizeStringArray($definition['sorts'] ?? []),
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

            foreach ($this->resolvePathIdentifiers($model) as $slug => $identifier) {
                $listPath = $this->composePath($basePath, $slug);

                if (!isset($paths[$listPath])) {
                    $paths[$listPath] = $this->buildModelListPathItem($model, $tag, $openApiConfig, $identifier);
                }

                if ($this->modelHasAction($model, 'update') || $this->modelHasAction($model, 'delete')) {
                    $detailPath = $listPath === '/' ? '/{id}' : rtrim($listPath, '/') . '/{id}';

                    if (!isset($paths[$detailPath])) {
                        $paths[$detailPath] = $this->buildModelDetailPathItem($model, $tag, $openApiConfig, $identifier);
                    }
                }
            }
        }

        return $paths;
    }

    protected function resolvePathIdentifiers(array $model): array
    {
        $map = [];

        if (!empty($model['aliases'])) {
            foreach ($model['aliases'] as $alias) {
                if (!is_string($alias) || $alias === '') {
                    continue;
                }

                $slug = $this->slugifyPathSegment($alias);
                $map[$slug] = $alias;
            }
        }

        $classSlug = $this->slugifyPathSegment($model['model']);
        $map[$classSlug] = $model['model'];

        return $map;
    }

    protected function slugifyPathSegment(string $value): string
    {
        $slug = Str::slug($value, '-');

        if ($slug === '') {
            $slug = 'model-' . substr(md5($value), 0, 8);
        }

        return $slug;
    }

    protected function composePath(string $basePath, string $segment): string
    {
        if ($basePath === '/') {
            return '/' . $segment;
        }

        return rtrim($basePath, '/') . '/' . $segment;
    }

    protected function modelHasAction(array $model, string $action): bool
    {
        return isset($model['actions'][$action]);
    }

    protected function buildModelListPathItem(
        array $model,
        string $tag,
        array $openApiConfig,
        string $identifier
    ): array {
        $pathParameters = [
            $this->buildModelParameter($model, $identifier),
        ];

        return array_filter([
            'parameters' => array_values(array_filter($pathParameters, static fn ($value) => $value !== null)),
            'get' => $this->buildModelIndexOperation($model, $tag, $openApiConfig),
            'post' => $this->modelHasAction($model, 'create')
                ? $this->buildModelActionOperation('create', $model, $tag, $openApiConfig)
                : null,
        ]);
    }

    protected function buildModelDetailPathItem(
        array $model,
        string $tag,
        array $openApiConfig,
        string $identifier
    ): array {
        $pathParameters = [
            $this->buildModelParameter($model, $identifier),
            $this->buildIdentifierParameter(),
        ];

        return array_filter([
            'parameters' => array_values(array_filter($pathParameters, static fn ($value) => $value !== null)),
            'patch' => $this->modelHasAction($model, 'update')
                ? $this->buildModelActionOperation('update', $model, $tag, $openApiConfig)
                : null,
            'delete' => $this->modelHasAction($model, 'delete')
                ? $this->buildModelDeleteOperation($model, $tag, $openApiConfig)
                : null,
        ]);
    }

    protected function buildModelIndexOperation(array $model, string $tag, array $openApiConfig): array
    {
        $plural = $this->resolveModelPluralName($model);

        $filterParameter = $this->buildFilterParameter($model);

        $parameters = array_filter([
            $filterParameter,
            $this->buildSortParameter($model['sorts']),
            $this->buildCursorParameter(),
        ]);

        return $this->removeEmptyValues([
            'summary' => 'List ' . $plural,
            'description' => 'Returns ' . strtolower($plural) . ' applying Query Gate filters, sorting, selection, and pagination rules.',
            'tags' => [$tag],
            'parameters' => array_values($parameters),
            'responses' => [
                '200' => [
                    'description' => 'Successful response.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'description' => 'The shape depends on the selected model and pagination mode.',
                            ],
                            'example' => $this->buildListResponseExample($model),
                        ],
                    ],
                ],
            ],
            'security' => $this->buildSecurityRequirement($openApiConfig),
        ]);
    }

    protected function buildModelActionOperation(
        string $action,
        array $model,
        string $tag,
        array $openApiConfig
    ): array {
        $singular = $this->resolveModelSingularName($model);

        return $this->removeEmptyValues([
            'summary' => ucfirst($action) . ' ' . $singular,
            'description' => 'Executes the "' . $action . '" action for ' . strtolower($singular) . ' via Query Gate.',
            'tags' => [$tag],
            'requestBody' => $this->buildModelRequestBody($model, $action),
            'responses' => $this->buildActionResponses($action, $model),
            'security' => $this->buildSecurityRequirement($openApiConfig),
        ]);
    }

    protected function buildModelDeleteOperation(array $model, string $tag, array $openApiConfig): array
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
        ]);
    }

    protected function buildModelRequestBody(array $model, string $action): ?array
    {
        return $this->buildRequestBody([$model], $action);
    }

    protected function buildModelParameter(array $model, string $defaultIdentifier): array
    {
        $identifiers = array_values(array_unique(array_filter(array_merge(
            $model['aliases'],
            [$model['model']]
        ), static function ($value) {
            return is_string($value) && $value !== '';
        })));

        if (!in_array($defaultIdentifier, $identifiers, true)) {
            $identifiers[] = $defaultIdentifier;
        }

        return [
            'name' => 'model',
            'in' => 'query',
            'required' => true,
            'description' => 'Fixed model identifier for this endpoint.',
            'schema' => array_filter([
                'type' => 'string',
                'enum' => $identifiers,
                'default' => $defaultIdentifier,
            ], static fn ($value) => $value !== null),
            'example' => $defaultIdentifier,
        ];
    }

    protected function buildListResponseExample(array $model): array
    {
        return [
            'data' => [
                $this->buildRecordExample($model),
            ],
        ];
    }

    protected function buildRecordExample(array $model): array
    {
        $select = $model['select'] ?? [];

        if (!is_array($select) || $select === []) {
            return [
                'id' => 'undefined',
            ];
        }

        return $this->buildSelectionExample($select);
    }

    protected function buildSelectionExample(array $select): array
    {
        $tree = [];

        foreach ($select as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $segments = array_map('trim', explode('.', $path));
            $segments = array_filter($segments, static fn ($segment) => $segment !== '');

            if ($segments === []) {
                continue;
            }

            $this->addSelectionPath($tree, $segments);
        }

        if ($tree === []) {
            return [
                'id' => 'undefined',
            ];
        }

        return $this->selectionTreeToExample($tree);
    }

    protected function addSelectionPath(array &$tree, array $segments): void
    {
        $segment = array_shift($segments);

        if ($segment === null || $segment === '') {
            return;
        }

        if (!isset($tree[$segment])) {
            $tree[$segment] = [
                'children' => [],
                'leaf' => false,
            ];
        }

        if ($segments === []) {
            $tree[$segment]['leaf'] = true;

            return;
        }

        $this->addSelectionPath($tree[$segment]['children'], $segments);
    }

    protected function selectionTreeToExample(array $tree): array
    {
        $example = [];

        foreach ($tree as $field => $node) {
            $children = $node['children'] ?? [];
            $leaf = $node['leaf'] ?? false;

            if ($children !== []) {
                $example[$field] = [
                    $this->selectionTreeToExample($children),
                ];
                continue;
            }

            if ($leaf) {
                $example[$field] = 'undefined';
            }
        }

        if ($example === []) {
            return [
                'id' => 'undefined',
            ];
        }

        return $example;
    }

    protected function buildActionRequestExample(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $example = [];

        foreach ($fields as $field => $rules) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $example[$field] = 'undefined';
        }

        return $example;
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

    protected function buildFilterParameter(array $model): ?array
    {
        $filters = $model['filters'] ?? [];

        if ($filters === []) {
            return null;
        }

        $properties = [];

        foreach ($filters as $field => $metadata) {
            $operators = $metadata['operators'] ?? FilterParser::SUPPORTED_OPERATORS;

            $operatorProperties = [];

            foreach ($operators as $operator) {
                if (!is_string($operator) || $operator === '') {
                    continue;
                }

                $operatorProperties[$operator] = array_filter([
                    'type' => 'string',
                    'description' => $this->describeOperator($operator, $metadata['rules'] ?? []),
                    'example' => $this->exampleForOperator($operator),
                ]);
            }

            $properties[$field] = array_filter([
                'type' => 'object',
                'properties' => $operatorProperties,
                'description' => $this->describeRules($metadata['rules'] ?? []),
                'additionalProperties' => true,
            ]);
        }

        if ($properties === []) {
            return null;
        }

        return [
            'name' => 'filter',
            'in' => 'query',
            'required' => false,
            'description' => 'Filter definitions using the format filter[field][operator]=value.',
            'style' => 'deepObject',
            'explode' => true,
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                'additionalProperties' => true,
            ],
        ];
    }

    protected function buildSortParameter(array $allowedSorts = []): array
    {
        $description = 'Comma-separated sort instructions (e.g. created_at:desc,id:asc).';

        if ($allowedSorts !== []) {
            $description .= ' Allowed fields: ' . implode(', ', $allowedSorts) . '.';
        }

        return array_filter([
            'name' => 'sort',
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'schema' => [
                'type' => 'string',
            ],
            'x-allowed-sorts' => $allowedSorts !== [] ? $allowedSorts : null,
        ], static fn ($value) => $value !== null);
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
    protected function buildActionResponses(string $action, array $model): array
    {
        if ($action === 'create') {
            return [
                '201' => [
                    'description' => 'Resource created successfully.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                            ],
                            'example' => $this->buildRecordExample($model),
                        ],
                    ],
                ],
            ];
        }

        if ($action === 'update') {
            return [
                '200' => [
                    'description' => 'Resource updated successfully.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                            ],
                            'example' => $this->buildRecordExample($model),
                        ],
                    ],
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
                'sorts' => $model['sorts'] !== [] ? [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ] : null,
                'filters' => $filterProperties !== [] ? [
                    'type' => 'object',
                    'properties' => $filterProperties,
                ] : null,
                'actions' => $actions !== [] ? [
                    'type' => 'object',
                    'properties' => $actions,
                ] : null,
            ]),
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

        $example = $this->buildActionRequestExample($actionData['validation']['fields'] ?? []);

        if ($example !== []) {
            $schema['example'] = $example;
        }

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

    protected function describeRules(array $rules): ?string
    {
        if ($rules === []) {
            return null;
        }

        return 'Validation: ' . implode(', ', $rules);
    }

    protected function describeOperator(string $operator, array $rules): ?string
    {
        $description = match ($operator) {
            'eq' => 'Equals comparison',
            'neq' => 'Not equals comparison',
            'lt' => 'Less than comparison',
            'lte' => 'Less than or equal comparison',
            'gt' => 'Greater than comparison',
            'gte' => 'Greater than or equal comparison',
            'like' => 'SQL LIKE comparison (use % for wildcards)',
            'in' => 'Comma separated list of values',
            'not_in' => 'Comma separated list of values to exclude',
            'between' => 'Comma separated start and end values',
            default => null,
        };

        if ($rules !== [] && $description !== null) {
            return $description . '. ' . $this->describeRules($rules);
        }

        if ($rules !== []) {
            return $this->describeRules($rules);
        }

        return $description;
    }

    protected function exampleForOperator(string $operator): ?string
    {
        return match ($operator) {
            'between' => 'undefined,undefined',
            'in', 'not_in' => 'undefined,undefined',
            default => 'undefined',
        };
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


