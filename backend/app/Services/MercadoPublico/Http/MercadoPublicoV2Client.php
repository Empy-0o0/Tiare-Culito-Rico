<?php

namespace App\Services\MercadoPublico\Http;

use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Sync\MercadoPublicoQuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Cliente HTTP de la API Compra Ágil V2 (https://api2.mercadopublico.cl).
 *
 * - Ticket SOLO en el header `ticket` (guía oficial v3.0 §3). Nunca en query string ni logs.
 * - TLS verificado (no se desactiva), timeout y connect timeout.
 * - Reintentos solo para 500/503/errores de conexión, con backoff exponencial + jitter.
 * - 429: sin reintento; respeta Retry-After o bloquea hasta el próximo día calendario.
 * - Cada intento HTTP consume cuota local (MercadoPublicoQuotaService).
 */
class MercadoPublicoV2Client
{
    public const API = 'v2';

    private int $requestCount = 0;

    public function __construct(
        private readonly MercadoPublicoConfig $config,
        private readonly MercadoPublicoQuotaService $quota,
    ) {}

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed> payload validado
     */
    public function get(string $path, array $query = []): array
    {
        if (! $this->config->enabled()) {
            throw MercadoPublicoException::disabled();
        }
        if (! $this->config->hasTicket()) {
            throw MercadoPublicoException::notConfigured();
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $this->quota->reserve(self::API);
            $this->requestCount++;

            try {
                $response = Http::baseUrl($this->config->v2BaseUrl())
                    ->withHeaders(['ticket' => $this->config->ticket(), 'Accept' => 'application/json'])
                    ->withUserAgent('TIARE-Platform/1.0')
                    ->timeout($this->config->timeout())
                    ->connectTimeout($this->config->connectTimeout())
                    ->get($path, $query);
            } catch (ConnectionException) {
                $this->quota->recordError(self::API);
                $error = new MercadoPublicoException(MercadoPublicoException::UPSTREAM_UNAVAILABLE, 'No fue posible conectar con Mercado Público.');
                if ($attempt <= $this->config->maxRetries()) {
                    $this->backoff($attempt);

                    continue;
                }
                throw $error;
            }

            if ($response->successful()) {
                return $this->extractPayload($response, $path);
            }

            $this->quota->recordError(self::API);
            $error = $this->mapError($response);
            $this->logFailure($path, $response->status(), $error->errorCode);

            if ($error->errorCode === MercadoPublicoException::QUOTA_EXHAUSTED) {
                $this->quota->blockUntil(self::API, $error->retryAfter ?? $this->quota->nextBusinessDay());
                throw $error;
            }

            if ($error->isRetryable() && $attempt <= $this->config->maxRetries()) {
                $this->backoff($attempt);

                continue;
            }

            throw $error;
        }
    }

    /** Solicitudes HTTP realizadas por esta instancia (incluye reintentos). */
    public function requestCount(): int
    {
        return $this->requestCount;
    }

    /** @return array<string, mixed> */
    private function extractPayload(Response $response, string $path): array
    {
        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? null) !== 'OK' || ! is_array($json['payload'] ?? null)) {
            $this->logFailure($path, $response->status(), MercadoPublicoException::INVALID_RESPONSE);

            throw new MercadoPublicoException(
                MercadoPublicoException::INVALID_RESPONSE,
                'Mercado Público respondió con un formato inesperado.',
                $response->status()
            );
        }

        return $json['payload'];
    }

    private function mapError(Response $response): MercadoPublicoException
    {
        $status = $response->status();
        $detail = $this->safeDetail($response);

        return match (true) {
            $status === 400 => new MercadoPublicoException(MercadoPublicoException::INVALID_PARAMETERS, 'Parámetros rechazados por Mercado Público'.($detail ? ": {$detail}" : '.'), 400),
            $status === 401, $status === 403 => new MercadoPublicoException(MercadoPublicoException::UNAUTHORIZED, 'Mercado Público rechazó el ticket configurado (inexistente, inválido, inactivo o bloqueado).', $status),
            $status === 404 => new MercadoPublicoException(MercadoPublicoException::NOT_FOUND, 'El registro no existe o no es público en Mercado Público.', 404),
            $status === 429 => MercadoPublicoException::quotaExhausted($this->retryAfter($response), 'Mercado Público informó que la cuota diaria del ticket se agotó.'),
            $status >= 500 => new MercadoPublicoException(MercadoPublicoException::UPSTREAM_UNAVAILABLE, 'Mercado Público no está disponible temporalmente.', $status),
            default => new MercadoPublicoException(MercadoPublicoException::INVALID_RESPONSE, "Respuesta no esperada de Mercado Público (HTTP {$status}).", $status),
        };
    }

    /** Detalle del error externo, acotado y sin datos sensibles. */
    private function safeDetail(Response $response): ?string
    {
        $errors = $response->json('errors');
        if (! is_array($errors) || ! isset($errors[0]) || ! is_array($errors[0])) {
            return null;
        }
        $text = (string) ($errors[0]['detalle'] ?? $errors[0]['mensaje'] ?? '');
        $text = str_ireplace($this->config->ticket(), '[REDACTED]', $text);

        return $text === '' ? null : Str::limit(strip_tags($text), 200);
    }

    private function retryAfter(Response $response): ?CarbonImmutable
    {
        $header = trim($response->header('Retry-After'));
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return CarbonImmutable::now()->addSeconds((int) $header);
        }
        try {
            return CarbonImmutable::parse($header);
        } catch (\Throwable) {
            return null;
        }
    }

    private function backoff(int $attempt): void
    {
        $base = $this->config->retryBaseMs() * (2 ** ($attempt - 1));
        $jitter = random_int(0, max(1, intdiv($base, 2)));
        Sleep::for($base + $jitter)->milliseconds();
    }

    /** Log sin ticket ni query string: solo ruta, estado y código interno. */
    private function logFailure(string $path, int $status, string $code): void
    {
        Log::warning('mercado_publico.v2.request_failed', [
            'path' => Str::before($path, '?'),
            'status' => $status,
            'code' => $code,
        ]);
    }
}
