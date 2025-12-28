<?php

namespace BehindSolution\LaravelQueryGate\Http\Controllers;

use BehindSolution\LaravelQueryGate\OpenApi\DocumentExtender;
use BehindSolution\LaravelQueryGate\OpenApi\OpenApiGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SwaggerController
{
    public function json(OpenApiGenerator $generator, DocumentExtender $extender): JsonResponse
    {
        $config = config('query-gate');

        if (!is_array($config) || empty($config['swagger']['enabled'])) {
            abort(404);
        }

        $document = $extender->extend($generator->generate($config), $config);

        return response()->json($document);
    }

    public function ui(Request $request): Response
    {
        $config = config('query-gate');

        if (!is_array($config) || empty($config['swagger']['enabled'])) {
            abort(404);
        }

        $title = is_string($config['swagger']['title'] ?? null)
            ? $config['swagger']['title']
            : 'Query Gate API';

        $jsonUrl = route('query-gate.swagger.json');

        $ui = is_string($config['swagger']['ui'] ?? null) ? strtolower($config['swagger']['ui']) : 'redoc';
        $uiOptions = is_array($config['swagger']['ui_options'] ?? null) ? $config['swagger']['ui_options'] : [];

        return response()->view('query-gate::swagger', [
            'title' => $title,
            'jsonUrl' => $jsonUrl,
            'ui' => $ui,
            'uiOptions' => $uiOptions,
        ]);
    }
}


