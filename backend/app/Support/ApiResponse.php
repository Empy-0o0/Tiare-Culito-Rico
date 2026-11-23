<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Envoltorio estándar: {data, meta:{request_id,...}} y errores {error:{code,message,details}, meta}.
 */
final class ApiResponse
{
    public static function requestId(): string
    {
        return (string) (request()->attributes->get('request_id') ?? '');
    }

    public static function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => self::requestId()] + $meta], $status);
    }

    public static function paginated(LengthAwarePaginator $paginator, mixed $data, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta + [
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public static function error(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        $body = ['error' => ['code' => $code, 'message' => $message], 'meta' => ['request_id' => self::requestId()]];
        if ($details !== []) {
            $body['error']['details'] = $details;
        }

        return response()->json($body, $status, $headers);
    }
}
