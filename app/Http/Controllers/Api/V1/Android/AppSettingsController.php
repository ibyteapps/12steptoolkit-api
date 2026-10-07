<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;

/**
 * `get_app_settings.php` — the one global configuration row.
 *
 * Public, as it is today: it holds text limits, ad timings and a sale window,
 * and nothing about anybody. The key is **`data`**, not `response` — the one
 * endpoint in the v19 API that breaks its own envelope. Both clients parse it
 * that way, so it stays.
 */
class AppSettingsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Settings fetched',
            'data' => AppSetting::row(),
        ]);
    }
}
