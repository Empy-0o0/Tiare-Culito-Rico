<?php

namespace App\Services\MercadoPublico\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Error del conector Mercado Público. Los mensajes son seguros para mostrar:
 * nunca contienen el ticket, URLs con query string ni payloads completos.
 */
class MercadoPublicoException extends RuntimeException
{
    public const DISABLED = 'MP_DISABLED';

    public const NOT_CONFIGURED = 'MP_NOT_CONFIGURED';

    public const INVALID_PARAMETERS = 'MP_INVALID_PARAMETERS';

    public const UNAUTHORIZED = 'MP_TICKET_REJECTED';

    public const NOT_FOUND = 'MP_NOT_FOUND';

    public const QUOTA_EXHAUSTED = 'MP_QUOTA_EXHAUSTED';

    public const UPSTREAM_UNAVAILABLE = 'MP_UPSTREAM_UNAVAILABLE';

    public const INVALID_RESPONSE = 'MP_INVALID_RESPONSE';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $upstreamStatus = null,
        public readonly ?CarbonImmutable $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return $this->errorCode === self::UPSTREAM_UNAVAILABLE;
    }

    /** Código HTTP que la API interna de TIARE devuelve a React. */
    public function internalHttpStatus(): int
    {
        return match ($this->errorCode) {
            self::INVALID_PARAMETERS => 422,
            self::NOT_FOUND => 404,
            self::QUOTA_EXHAUSTED => 429,
            self::DISABLED, self::NOT_CONFIGURED, self::UNAUTHORIZED, self::UPSTREAM_UNAVAILABLE => 503,
            default => 502,
        };
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'La integración con Mercado Público está deshabilitada.');
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'Falta configurar el ticket de Mercado Público en el servidor.');
    }

    public static function quotaExhausted(?CarbonImmutable $retryAfter, string $reason): self
    {
        return new self(self::QUOTA_EXHAUSTED, $reason, 429, $retryAfter);
    }
}
