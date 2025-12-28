<?php

namespace BehindSolution\LaravelQueryGate\Http\Controllers;

use BehindSolution\LaravelQueryGate\OpenApi\OpenApiGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SwaggerController
{
    public function json(OpenApiGenerator $generator): JsonResponse
    {
        $config = config('query-gate');

        if (!is_array($config) || empty($config['swagger']['enabled'])) {
            abort(404);
        }

        $document = $generator->generate($config);

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

        return response()->view('query-gate::swagger', [
            'title' => $title,
            'jsonUrl' => $jsonUrl,
        ]);
    }
}


