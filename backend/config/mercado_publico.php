<?php

/*
|--------------------------------------------------------------------------
| Integración Mercado Público (Dirección ChileCompra)
|--------------------------------------------------------------------------
| Ver docs/MERCADO_PUBLICO_API.md. El ticket vive SOLO en backend/.env.
| Nunca exponer este archivo de configuración a respuestas HTTP ni logs.
*/

return [
    'enabled' => (bool) env('MERCADO_PUBLICO_ENABLED', false),

    // Secreto. No registrar, no serializar, no devolver al frontend.
    'ticket' => env('MERCADO_PUBLICO_TICKET'),

    'v2' => [
        'base_url' => env('MERCADO_PUBLICO_V2_BASE_URL', 'https://api2.mercadopublico.cl'),
        // Cuota real depende del tipo de ticket (no publicada). Límite operativo conservador.
        'daily_budget' => (int) env('MERCADO_PUBLICO_V2_DAILY_BUDGET', 250),
        // Solicitudes del día que las sincronizaciones automáticas NO pueden usar: quedan para
        // fichas de detalle y sincronizaciones manuales de las personas (TD-029).
        'interactive_reserve' => (int) env('MERCADO_PUBLICO_V2_INTERACTIVE_RESERVE', 70),
        // La respuesta real rotula con "Z" horas que parecen locales de Chile (TD-005).
        'z_is_local' => (bool) env('MERCADO_PUBLICO_V2_Z_IS_LOCAL', true),
        // Validado contra la API real: rechaza valores fuera de 10..50.
        'page_size_min' => 10,
        'page_size_max' => 50,
    ],

    'v1' => [
        'base_url' => env('MERCADO_PUBLICO_V1_BASE_URL', 'https://api.mercadopublico.cl/servicios/v1/publico'),
        'daily_budget' => (int) env('MERCADO_PUBLICO_V1_DAILY_BUDGET', 9000),
        // Rutas relativas a base_url (diccionarios oficiales, marzo 2026). Configurables por si ChileCompra las cambia.
        'paths' => [
            'tenders' => env('MERCADO_PUBLICO_V1_TENDERS_PATH', '/licitaciones.json'),
            'purchase_orders' => env('MERCADO_PUBLICO_V1_ORDERS_PATH', '/ordenesdecompra.json'),
            'supplier_lookup' => env('MERCADO_PUBLICO_V1_SUPPLIER_PATH', '/Empresas/BuscarProveedor'),
            'buyers' => env('MERCADO_PUBLICO_V1_BUYERS_PATH', '/Empresas/BuscarComprador'),
        ],
        // Listados V1 (por fecha/estado) responden en una sola solicitud: se guardan los códigos resultantes.
        'list_cache_minutes' => (int) env('MERCADO_PUBLICO_V1_LIST_CACHE_MINUTES', 30),
        // Directorio de organismos compradores (BuscarComprador): se refresca como máximo 1 vez al día.
        'buyers_refresh_hours' => (int) env('MERCADO_PUBLICO_V1_BUYERS_REFRESH_HOURS', 24),
        'http_timeout' => (int) env('MERCADO_PUBLICO_V1_HTTP_TIMEOUT', 45),
    ],

    'http' => [
        'timeout' => (int) env('MERCADO_PUBLICO_HTTP_TIMEOUT', 20),
        'connect_timeout' => (int) env('MERCADO_PUBLICO_HTTP_CONNECT_TIMEOUT', 5),
        'max_retries' => (int) env('MERCADO_PUBLICO_MAX_RETRIES', 2),
        'retry_base_ms' => (int) env('MERCADO_PUBLICO_RETRY_BASE_MS', 500),
    ],

    'sync' => [
        'enabled' => (bool) env('MERCADO_PUBLICO_SYNC_ENABLED', false),
        'interval_minutes' => (int) env('MERCADO_PUBLICO_SYNC_INTERVAL_MINUTES', 60),
        'overlap_minutes' => (int) env('MERCADO_PUBLICO_SYNC_OVERLAP_MINUTES', 15),
        'initial_window_hours' => (int) env('MERCADO_PUBLICO_SYNC_INITIAL_WINDOW_HOURS', 24),
        'max_window_hours' => (int) env('MERCADO_PUBLICO_SYNC_MAX_WINDOW_HOURS', 72),
        'max_pages' => (int) env('MERCADO_PUBLICO_SYNC_MAX_PAGES', 10),
        // Modo por defecto (TD-029): solo publicaciones nuevas, de la más reciente hacia atrás,
        // hasta llegar a lo ya conocido. "changes" = todos los cambios del país (caro; uso puntual nocturno).
        'mode' => env('MERCADO_PUBLICO_SYNC_MODE', 'publications'),
        // Una sincronización manual pedida antes de este plazo no consulta la API: informa la última.
        'manual_cooldown_minutes' => (int) env('MERCADO_PUBLICO_SYNC_MANUAL_COOLDOWN_MINUTES', 10),
    ],

    // Vigencia de la ficha de detalle guardada antes de volver a pedirla a la API (TD-029).
    'cache_minutes' => (int) env('MERCADO_PUBLICO_CACHE_MINUTES', 60),           // procesos abiertos
    'closed_cache_hours' => (int) env('MERCADO_PUBLICO_CLOSED_CACHE_HOURS', 24), // cerrados/finalizados: cambian poco

    'business_timezone' => 'America/Santiago',

    // Enlace oficial. No hay un enlace profundo verificado a la ficha de Compra Ágil
    // (PEND-T07): se enlaza al portal y se muestra el código para buscarlo.
    // Búsquedas guardadas y alertas internas (sobre datos ya sincronizados).
    'matching' => [
        'enabled' => (bool) env('MERCADO_PUBLICO_MATCHING_ENABLED', true),
        'max_searches_per_tenant' => (int) env('MERCADO_PUBLICO_MAX_SAVED_SEARCHES', 20),
        // Licitaciones (API V1): 1 consulta de activas + hasta N detalles por ejecución, cada pocas horas.
        'tender_watch_enabled' => (bool) env('MERCADO_PUBLICO_TENDER_WATCH_ENABLED', true),
        'tender_watch_interval_hours' => (int) env('MERCADO_PUBLICO_TENDER_WATCH_HOURS', 3),
        'tender_details_per_run' => (int) env('MERCADO_PUBLICO_TENDER_DETAILS_PER_RUN', 40),
    ],

    'portal' => [
        'home_url' => 'https://www.mercadopublico.cl/',
        'source_name' => 'Dirección ChileCompra / Mercado Público',
    ],
];
