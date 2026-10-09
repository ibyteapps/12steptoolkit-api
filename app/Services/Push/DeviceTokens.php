<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where an account's push tokens live, which is two places.
 *
 * `devices.fcm_code` is one row per device, and `accounts.fcm_token` is the
 * single most-recent one the older scripts kept. Both are read, because a
 * member who has not opened the new client since upgrading has the second and
 * not the first.
 */
class DeviceTokens
{
    /** @return array<int, string> */
    public function for(int $accountId): array
    {
        $tokens = [];

        if (Schema::hasTable('devices')) {
            $tokens = DB::table('devices')
                ->where('account_id', $accountId)
                ->whereNotNull('fcm_code')
                ->where('fcm_code', '!=', '')
                ->pluck('fcm_code')
                ->all();
        }

        $legacy = DB::table('accounts')->where('id', $accountId)->value('fcm_token');

        if (is_string($legacy) && $legacy !== '') {
            $tokens[] = $legacy;
        }

        return array_values(array_unique(array_map('strval', $tokens)));
    }
}
