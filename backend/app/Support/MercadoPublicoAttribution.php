<?php

namespace App\Support;

/** Atribución obligatoria de ChileCompra (condiciones de uso, punto 4) para toda respuesta con datos oficiales. */
final class MercadoPublicoAttribution
{
    public static function source(string $api): array
    {
        return [
            'name' => config('mercado_publico.portal.source_name'),
            'api' => $api,
            'portal_url' => config('mercado_publico.portal.home_url'),
            'data_origin' => 'official',
            'timezone' => 'America/Santiago',
        ];
    }
}
