<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The single response envelope for every /api/v2 endpoint:
 *
 *   { "success": bool, "data": mixed, "message": string|null, "errors": object|null, "meta": object }
 *
 * Legacy /api/v1 endpoints deliberately do NOT use this — they reproduce the
 * historic plain-text / JSON-array responses of the PHP scripts.
 */
final class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            $data = $data->resolve(request());
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'errors' => null,
            'meta' => (object) $meta,
        ], $status);
    }

    public static function created(mixed $data = null, ?string $message = null, array $meta = []): JsonResponse
    {
        return self::success($data, $message, 201, $meta);
    }

    public static function error(string $message, int $status, ?array $errors = null, array $meta = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'errors' => $errors === null ? null : (object) $errors,
            'meta' => (object) $meta,
        ], $status, $headers);
    }
}
