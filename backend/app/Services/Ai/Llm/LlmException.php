<?php

namespace App\Services\Ai\Llm;

use RuntimeException;

/** Error del modelo, con mensaje apto para mostrar (nunca incluye la clave ni el payload). */
final class LlmException extends RuntimeException
{
    public const NOT_CONFIGURED = 'AI_NOT_CONFIGURED';

    public const AUTH = 'AI_AUTH_FAILED';

    public const RATE_LIMITED = 'AI_RATE_LIMITED';

    public const UNAVAILABLE = 'AI_UNAVAILABLE';

    public const REFUSED = 'AI_REFUSED';

    public const INVALID_OUTPUT = 'AI_INVALID_OUTPUT';

    public const BAD_REQUEST = 'AI_BAD_REQUEST';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
