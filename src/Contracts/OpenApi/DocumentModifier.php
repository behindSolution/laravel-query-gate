<?php

namespace BehindSolution\LaravelQueryGate\Contracts\OpenApi;

interface DocumentModifier
{
    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function modify(array $document): array;
}


