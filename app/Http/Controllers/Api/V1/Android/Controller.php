<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\Account;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What every v19 endpoint shares.
 *
 * The one rule worth stating: **the account comes from the token, never from
 * the body.** The old scripts read `$_POST['account_id']` and relied on
 * `AND accountid = ?` to contain the damage; `19/get_comments.php` goes further
 * and *defaults* `account_id` to a hard-coded real account when the field is
 * missing. Here the body's `account_id` is only ever checked for agreement, and
 * a disagreement is a refusal rather than a quiet substitution — a client that
 * has its accounts muddled should find out immediately.
 */
abstract class Controller extends \App\Http\Controllers\Controller
{
    protected function account(Request $request): Account
    {
        return $request->attributes->get('legacy_account');
    }

    /** Null when the body agrees (or says nothing); a 403 response when it does not. */
    protected function accountMismatch(Request $request): ?JsonResponse
    {
        $claimed = $request->input('account_id');
        if ($claimed === null || $claimed === '') {
            return null;
        }

        if ((int) $claimed !== (int) $this->account($request)->id) {
            return LegacyEnvelope::fail('Forbidden', 403);
        }

        return null;
    }
}
