<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Interpreta fechas de APIs externas y devuelve UTC.
 *
 * Compra Ágil V2 mezcla formatos (TD-005): "2026-11-13 21:59" (sin zona) y
 * "2026-11-16T11:00:00Z". Las fechas sin zona se interpretan en America/Santiago.
 * Las fechas con "Z" se interpretan como hora local de Chile si $zIsLocal = true
 * (evidencia observada), o como UTC real si es false.
 */
final class SourceDateParser
{
    public static function parse(mixed $value, string $localTimezone, bool $zIsLocal): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);

        try {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)Z$/', $value, $m)) {
                return $zIsLocal
                    ? CarbonImmutable::parse("{$m[1]} {$m[2]}", $localTimezone)->utc()
                    : CarbonImmutable::parse($value)->utc();
            }

            // Offset explícito distinto de Z: se respeta.
            if (preg_match('/[+-]\d{2}:?\d{2}$/', $value)) {
                return CarbonImmutable::parse($value)->utc();
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?$/', $value)) {
                return CarbonImmutable::parse(str_replace('T', ' ', $value), $localTimezone)->utc();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
