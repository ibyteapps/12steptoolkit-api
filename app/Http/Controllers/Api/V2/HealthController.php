<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Is the application up, and can it reach its database. Nothing else. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $database = false;
        }

        return ApiResponse::success([
            'ok' => $database,
            'database' => $database,
            'time' => now()->toIso8601String(),
        ], status: $database ? 200 : 503);
    }
}
