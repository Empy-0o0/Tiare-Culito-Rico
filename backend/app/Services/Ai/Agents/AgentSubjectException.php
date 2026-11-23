<?php

namespace App\Services\Ai\Agents;

use RuntimeException;

/** El caso a analizar no existe, no pertenece a la empresa o no tiene datos suficientes. */
final class AgentSubjectException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
