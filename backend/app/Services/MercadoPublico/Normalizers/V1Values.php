<?php

namespace App\Services\MercadoPublico\Normalizers;

use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Support\SourceDateParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Lectura defensiva de valores V1: el diccionario oficial declara tipos, pero las respuestas
 * pueden traer null, cadenas vacías o números como texto.
 */
final class V1Values
{
    /** Claves con datos personales de funcionarios o contactos: no se almacenan (minimización). */
    private const PERSONAL_KEYS = [
        'RutUsuario', 'CodigoUsuario', 'NombreUsuario', 'CargoUsuario',
        'NombreContacto', 'CargoContacto', 'FonoContacto', 'MailContacto',
        'NombreResponsablePago', 'EmailResponsablePago',
        'NombreResponsableContrato', 'EmailResponsableContrato', 'FonoResponsableContrato',
    ];

    public function __construct(private readonly MercadoPublicoConfig $config) {}

    public function get(array $data, string $path): mixed
    {
        return data_get($data, $path);
    }

    public function str(array $data, string $path, int $max = 500): ?string
    {
        $v = data_get($data, $path);
        if (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : Str::limit($v, $max, '');
    }

    public function int(array $data, string $path): ?int
    {
        $v = data_get($data, $path);

        return is_numeric($v) ? (int) $v : null;
    }

    public function bool(array $data, string $path): ?bool
    {
        $v = data_get($data, $path);
        if (is_bool($v)) {
            return $v;
        }

        return is_numeric($v) ? ((int) $v) === 1 : null;
    }

    /** Montos DECIMAL como string (nunca FLOAT en base). */
    public function decimal(array $data, string $path, int $scale = 2): ?string
    {
        $v = data_get($data, $path);
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }

        return is_numeric($v) ? number_format((float) $v, $scale, '.', '') : null;
    }

    /** Fechas V1 sin zona: hora de Chile → UTC. Fechas centinela (año < 1990) se descartan. */
    public function date(array $data, string $path): ?CarbonImmutable
    {
        $date = SourceDateParser::parse(data_get($data, $path), $this->config->businessTimezone(), true);

        return $date && $date->year >= 1990 ? $date : null;
    }

    /** Solo enlaces HTTPS a hosts oficiales de Mercado Público (CLAUDE.md §6.5). */
    public function officialUrl(array $data, string $path): ?string
    {
        $url = $this->str($data, $path, 1000);
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ! ($host === 'mercadopublico.cl' || str_ends_with($host, '.mercadopublico.cl'))) {
            return null;
        }

        return 'https://'.$host.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** @return array<string, mixed> */
    public function scrub(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::PERSONAL_KEYS, true)) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function list(array $data, string ...$paths): array
    {
        foreach ($paths as $path) {
            $v = data_get($data, $path);
            if (is_array($v)) {
                return array_values(array_filter($v, 'is_array'));
            }
        }

        return [];
    }

    public function hash(array $data): string
    {
        return hash('sha256', (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function iso(?CarbonImmutable $d): ?string
    {
        return $d?->toIso8601String();
    }
}
