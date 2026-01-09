<?php

namespace BehindSolution\LaravelQueryGate\Contracts;

interface QueryGateAction
{
    /**
     * Unique identifier of the action (e.g. create, update, delete, refund).
     */
    public function action(): string;

    /**
     * HTTP method required to trigger this action.
     */
    public function method(): string;

    /**
     * Executes the business logic for the action.
     *
     * If a Response instance is returned, it will be used directly.
     * Otherwise the result is passed through to the default serializer.
     *
     * @param array<string, mixed> $payload
     * @return mixed
     */
    public function handle($request, $model, array $payload);

    /**
     * Optionally override the HTTP status code for non-Response results.
     */
    public function status(): ?int;

    /**
     * Validation rules to apply before handle() runs.
     *
     * @return array<string, mixed>
     */
    public function validations(): array;

    /**
     * Policy ability or abilities to authorize.
     *
     * @return array<int, string>|string|null
     */
    public function policy();

    /**
     * Fine-grained authorization hook. Return false to forbid.
     */
    public function authorize($request, $model): ?bool;

    /**
     * Optional display name for documentation purposes.
     */
    public function name(): ?string;
}
