<?php

namespace App\Services\MercadoPublico\Http;

use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Sync\MercadoPublicoQuotaService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Cliente HTTP de la API legacy V1 (https://api.mercadopublico.cl/servicios/v1/publico).
 *
 * - Los ejemplos oficiales envían el ticket como parámetro GET `ticket`. Como viaja en la URL:
 *   nunca se registra la URL, los mensajes de excepción del cliente HTTP se descartan y cualquier
 *   texto externo se redacta antes de salir de esta clase.
 * - Las solicitudes V1 se serializan con un lock de caché (una a la vez por instalación).
 * - Errores: HTTP 4xx/5xx, cuerpos no JSON (páginas de error ASP.NET) y cuerpos JSON con
 *   {Codigo, Mensaje} sin `Listado` se convierten en MercadoPublicoException.
 */
class MercadoPublicoV1Client
{
    public const API = 'v1';

    private const LOCK = 'mercado_publico:v1:request';

    /** Pausa ante "peticiones simultáneas" (HTTP 429 observado el 17-11-2026; ~4 s bastaron). */
    private const CONCURRENCY_PAUSE_MS = 3000;

    private int $requestCount = 0;

    public function __construct(
        private readonly MercadoPublicoConfig $config,
        private readonly MercadoPublicoQuotaService $quota,
    ) {}

    /**
     * @param  array<string, scalar>  $query  sin ticket (se agrega aquí)
     * @return array<string, mixed> JSON decodificado
     */
    public function get(string $path, array $query = []): array
    {
        if (! $this->config->enabled()) {
            throw MercadoPublicoException::disabled();
        }
        if (! $this->config->hasTicket()) {
            throw MercadoPublicoException::notConfigured();
        }

        try {
            return Cache::lock(self::LOCK, $this->config->v1Timeout() + 10)
                ->block($this->config->v1Timeout() + 5, fn () => $this->send($path, $query));
        } catch (LockTimeoutException) {
            throw new MercadoPublicoException(MercadoPublicoException::UPSTREAM_UNAVAILABLE, 'Hay otra consulta a Mercado Público en curso. Intenta nuevamente en unos segundos.');
        }
    }

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    /** @return array<string, mixed> */
    private function send(string $path, array $query): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $this->quota->reserve(self::API);
            $this->requestCount++;

            try {
                $response = Http::baseUrl($this->config->v1BaseUrl())
                    ->withHeaders(['Accept' => 'application/json'])
                    ->withUserAgent('TIARE-Platform/1.0')
                    ->timeout($this->config->v1Timeout())
                    ->connectTimeout($this->config->connectTimeout())
                    ->get($path, $query + ['ticket' => $this->config->ticket()]);
            } catch (ConnectionException) {
                // El mensaje original incluye la URL con el ticket: se descarta.
                $this->quota->recordError(self::API);
                $this->logFailure($path, 0, MercadoPublicoException::UPSTREAM_UNAVAILABLE);
                if ($attempt <= $this->config->maxRetries()) {
                    $this->backoff($attempt);

                    continue;
                }
                throw new MercadoPublicoException(MercadoPublicoException::UPSTREAM_UNAVAILABLE, 'No fue posible conectar con Mercado Público (API V1).');
            }

            $error = $this->detectError($response);
            if ($error === null) {
                return (array) $response->json();
            }

            $this->quota->recordError(self::API);
            $this->logFailure($path, $response->status(), $error->errorCode);

            if ($error->errorCode === MercadoPublicoException::QUOTA_EXHAUSTED) {
                $this->quota->blockUntil(self::API, $this->quota->nextBusinessDay());
                throw $error;
            }
            if ($error->isRetryable() && $attempt <= $this->config->maxRetries()) {
                // "Peticiones simultáneas" (HTTP 429): V1 necesita unos segundos entre consultas.
                if ($response->status() === 429) {
                    Sleep::for(self::CONCURRENCY_PAUSE_MS * $attempt)->milliseconds();
                }
                $this->backoff($attempt);

                continue;
            }
            throw $error;
        }
    }

    private function detectError(Response $response): ?MercadoPublicoException
    {
        $status = $response->status();
        $json = $response->json();
        $isJson = is_array($json);
        $message = $isJson ? $this->safeText((string) ($json['Mensaje'] ?? $json['mensaje'] ?? '')) : '';

        if ($response->successful()) {
            if (! $isJson) {
                return new MercadoPublicoException(MercadoPublicoException::INVALID_RESPONSE, 'Mercado Público (API V1) respondió con un formato inesperado.', $status);
            }
            // Error con HTTP 200: {Codigo, Mensaje} sin Listado/listaEmpresas.
            if (isset($json['Codigo']) && isset($json['Mensaje']) && ! isset($json['Listado']) && ! isset($json['listaEmpresas'])) {
                return $this->fromMessage($message, $status);
            }

            return null;
        }

        // El mensaje JSON manda sobre el código HTTP. Observado el 17-11-2026 con respuestas reales:
        // - HTTP 429 {"Mensaje": "...existen peticiones simultáneas."}: no es cuota; se reintenta tras una pausa.
        // - HTTP 500 {"Mensaje": "Los parámetros no son válidos."}: filtro inválido; no se reintenta.
        if ($message !== '') {
            return $this->fromMessage($message, $status);
        }
        if ($status === 429) {
            return MercadoPublicoException::quotaExhausted(null, 'Mercado Público informó que la cuota diaria del ticket se agotó (API V1).');
        }
        if ($status >= 500) {
            return new MercadoPublicoException(MercadoPublicoException::UPSTREAM_UNAVAILABLE, 'Mercado Público (API V1) no está disponible temporalmente.', $status);
        }
        if ($status === 401 || $status === 403) {
            return new MercadoPublicoException(MercadoPublicoException::UNAUTHORIZED, 'Mercado Público rechazó el ticket configurado para la API V1.', $status);
        }
        if ($status === 404) {
            return new MercadoPublicoException(MercadoPublicoException::NOT_FOUND, 'El registro no existe o no es público en Mercado Público.', 404);
        }

        // Observado el 17-11-2026: HTTP 400 con página HTML "Runtime Error" (sin detalle).
        return new MercadoPublicoException(
            MercadoPublicoException::INVALID_PARAMETERS,
            'Mercado Público (API V1) rechazó la consulta (HTTP '.$status.'). Revisa los filtros; si persiste, verifica que el ticket esté habilitado para la API V1.',
            $status,
        );
    }

    /** Clasifica los mensajes {Codigo, Mensaje} de V1 (formato no documentado; se interpreta por contenido). */
    private function fromMessage(string $message, int $status): MercadoPublicoException
    {
        $lower = Str::lower(Str::ascii($message));

        return match (true) {
            str_contains($lower, 'ticket') => new MercadoPublicoException(MercadoPublicoException::UNAUTHORIZED, 'Mercado Público rechazó el ticket: '.$message, $status),
            str_contains($lower, 'simultane') => new MercadoPublicoException(MercadoPublicoException::UPSTREAM_UNAVAILABLE, 'Mercado Público detectó consultas simultáneas: '.$message, $status),
            str_contains($lower, 'cuota') || str_contains($lower, 'limite') => MercadoPublicoException::quotaExhausted(null, 'Mercado Público informó límite de consultas: '.$message),
            str_contains($lower, 'no existe') || str_contains($lower, 'no se encontr') => new MercadoPublicoException(MercadoPublicoException::NOT_FOUND, $message, 404),
            default => new MercadoPublicoException(MercadoPublicoException::INVALID_PARAMETERS, 'Mercado Público rechazó la consulta: '.$message, $status),
        };
    }

    private function safeText(string $text): string
    {
        $ticket = $this->config->ticket();
        if ($ticket !== '') {
            $text = str_ireplace($ticket, '[REDACTED]', $text);
        }

        return Str::limit(trim(strip_tags($text)), 200);
    }

    private function backoff(int $attempt): void
    {
        $base = $this->config->retryBaseMs() * (2 ** ($attempt - 1));
        Sleep::for($base + random_int(0, max(1, intdiv($base, 2))))->milliseconds();
    }

    /** Sin URL ni query string (el ticket viaja en la query en V1). */
    private function logFailure(string $path, int $status, string $code): void
    {
        Log::warning('mercado_publico.v1.request_failed', [
            'path' => Str::before($path, '?'),
            'status' => $status,
            'code' => $code,
        ]);
    }
}
