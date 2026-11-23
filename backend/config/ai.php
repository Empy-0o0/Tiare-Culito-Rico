<?php

/*
| Capa de agentes IA (Fase 6). Desacoplada del proveedor: los agentes calculan
| hechos deterministas siempre y, si hay un modelo habilitado, agregan análisis
| redactado por el modelo (etiquetado como "estimación IA").
|
| La clave vive solo en backend/.env. Nunca se envía al navegador ni se registra.
*/
return [
    'enabled' => (bool) env('AI_ENABLED', false),

    // Proveedor del modelo. Hoy solo 'anthropic' (Claude API).
    'provider' => env('AI_PROVIDER', 'anthropic'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_MODEL', 'claude-opus-5-5'),
        // low | medium | high | xhigh | max. Claude Opus 5.5 usa medium por defecto.
        'effort' => env('AI_EFFORT', 'medium'),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 16000),
        // Reintento del lado del servidor en otro modelo si el solicitado declina (refusal).
        'server_side_fallback' => (bool) env('AI_SERVER_SIDE_FALLBACK', true),
        'timeout' => (float) env('AI_HTTP_TIMEOUT', 120),
        'max_retries' => (int) env('AI_MAX_RETRIES', 2),
    ],

    // Límite de ejecuciones con modelo por empresa y día (America/Santiago). Las deterministas no cuentan.
    'daily_runs_per_tenant' => (int) env('AI_DAILY_RUNS_PER_TENANT', 30),
];
