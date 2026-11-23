<?php

namespace App\Support;

/** RUT chileno: validación por módulo 11 y formato normalizado "12.345.678-5". */
final class ChileanRut
{
    public static function clean(string $rut): string
    {
        return strtoupper((string) preg_replace('/[^0-9kK]/', '', $rut));
    }

    public static function isValid(?string $rut): bool
    {
        if ($rut === null) {
            return false;
        }
        $clean = self::clean($rut);
        if (! preg_match('/^(\d{7,8})([0-9K])$/', $clean, $m)) {
            return false;
        }

        return self::checkDigit($m[1]) === $m[2];
    }

    public static function checkDigit(string $body): string
    {
        $sum = 0;
        $factor = 2;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $dv = 11 - ($sum % 11);

        return match ($dv) {
            11 => '0',
            10 => 'K',
            default => (string) $dv,
        };
    }

    /** Devuelve el RUT formateado con puntos y guion, o null si no es válido. */
    public static function format(?string $rut): ?string
    {
        if (! self::isValid($rut)) {
            return null;
        }
        $clean = self::clean((string) $rut);
        $body = substr($clean, 0, -1);

        return number_format((int) $body, 0, '', '.').'-'.substr($clean, -1);
    }
}
