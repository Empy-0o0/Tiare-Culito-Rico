<?php

namespace App\Domain\PublicProcurement;

/** Códigos de región documentados por ChileCompra (guía Compra Ágil v3.0 §5.1, Grupo 4). */
final class ChileRegion
{
    public const NAMES = [
        1 => 'Tarapacá',
        2 => 'Antofagasta',
        3 => 'Atacama',
        4 => 'Coquimbo',
        5 => 'Valparaíso',
        6 => "Libertador General Bernardo O'Higgins",
        7 => 'Maule',
        8 => 'Biobío',
        9 => 'La Araucanía',
        10 => 'Los Lagos',
        11 => 'Aysén del General Carlos Ibáñez del Campo',
        12 => 'Magallanes y de la Antártica Chilena',
        13 => 'Metropolitana de Santiago',
        14 => 'Los Ríos',
        15 => 'Arica y Parinacota',
        16 => 'Ñuble',
    ];

    public static function isValid(int $code): bool
    {
        return isset(self::NAMES[$code]);
    }

    public static function name(int $code): ?string
    {
        return self::NAMES[$code] ?? null;
    }

    /**
     * Código de región a partir de una glosa libre (V1 informa "RegionUnidad" como texto,
     * p. ej. "Región Metropolitana de Santiago" o "Región del Biobío"). Null si no hay coincidencia única.
     */
    public static function codeFromName(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $needle = self::key($name);
        $matches = [];
        foreach (self::NAMES as $code => $regionName) {
            if (str_contains($needle, self::key($regionName))) {
                $matches[$code] = strlen(self::key($regionName));
            }
        }
        if ($matches === []) {
            return null;
        }
        arsort($matches); // la coincidencia más larga gana ("Los Ríos" vs. "Ríos")

        return (int) array_key_first($matches);
    }

    private static function key(string $text): string
    {
        $letters = (string) preg_replace('/[^a-z]/', '', strtolower(\Illuminate\Support\Str::ascii($text)));

        return str_replace(['libertadorgeneralbernardo', 'delgeneralcarlosibanezdelcampo', 'ylaantarticachilena', 'desantiago'], '', $letters);
    }
}
