<?php

namespace App\Services\Ai\Llm;

/**
 * Contrato con el proveedor de modelos. Los agentes dependen de esta interfaz,
 * no de un proveedor concreto (CLAUDE.md §4.1).
 */
interface LlmClient
{
    /** true si hay un modelo configurado y habilitado. */
    public function available(): bool;

    public function provider(): string;

    /**
     * Pide al modelo una respuesta JSON que cumpla $request->schema.
     *
     * @throws LlmException
     */
    public function structured(LlmRequest $request): LlmResult;
}
