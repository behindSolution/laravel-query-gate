<?php

namespace BehindSolution\LaravelQueryGate\Console;

use Illuminate\Console\GeneratorCommand;

class MakeQueryGateActionCommand extends GeneratorCommand
{
    protected $name = 'qg:action';

    protected $description = 'Create a new Query Gate action class.';

    protected $type = 'Query Gate action';

    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/query-gate-action.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Actions\\QueryGate';
    }
}
