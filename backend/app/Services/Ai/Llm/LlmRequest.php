<?php

namespace App\Services\Ai\Llm;

final class LlmRequest
{
    /**
     * @param  string  $system  Instrucciones fijas del agente (versionadas).
     * @param  string  $input  Datos del caso, ya seleccionados por el backend.
     * @param  array<string, mixed>  $schema  JSON Schema estricto de la salida.
     */
    public function __construct(
        public readonly string $system,
        public readonly string $input,
        public readonly array $schema,
    ) {}
}
