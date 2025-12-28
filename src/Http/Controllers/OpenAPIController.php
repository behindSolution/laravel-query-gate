<?php

namespace BehindSolution\LaravelQueryGate\Http\Controllers;

use BehindSolution\LaravelQueryGate\OpenApi\DocumentExtender;
use BehindSolution\LaravelQueryGate\OpenApi\OpenApiGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OpenAPIController
{
    public function json(OpenApiGenerator $generator, DocumentExtender $extender): JsonResponse
    {
        $config = config('query-gate');

        if (!is_array($config) || empty($config['openAPI']['enabled'])) {
            abort(404);
        }

        $document = $extender->extend($generator->generate($config), $config);

        return response()->json($document);
    }

    public function ui(Request $request): Response
    {
        $config = config('query-gate');

        if (!is_array($config) || empty($config['openAPI']['enabled'])) {
            abort(404);
        }

        $title = is_string($config['openAPI']['title'] ?? null)
            ? $config['openAPI']['title']
            : 'Query Gate API';

        $jsonUrl = route('query-gate.openAPI.json');

        $ui = is_string($config['openAPI']['ui'] ?? null) ? strtolower($config['openAPI']['ui']) : 'redoc';
        $uiOptions = is_array($config['openAPI']['ui_options'] ?? null) ? $config['openAPI']['ui_options'] : [];

        return response()->view('query-gate::openAPI', [
            'title' => $title,
            'jsonUrl' => $jsonUrl,
            'ui' => $ui,
            'uiOptions' => $uiOptions,
        ]);
    }
}


