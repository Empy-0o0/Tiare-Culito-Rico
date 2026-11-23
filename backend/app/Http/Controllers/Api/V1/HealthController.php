<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Health check público: sin secretos ni detalles de infraestructura. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unavailable';
        }

        return ApiResponse::ok([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'time' => now()->toIso8601String(),
        ], status: $database === 'ok' ? 200 : 503);
    }
}
