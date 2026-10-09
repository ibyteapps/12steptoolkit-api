<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Services\Accounts\AccountEraser;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Erasing one's own account.
 *
 * `18/deleteaccount.php` takes `accountid` from the POST body and erases it.
 * There is no authentication on that script, so a request naming any id
 * erased that member: their inventories, their journals, their sponsorships.
 * It is the most destructive of the unauthenticated endpoints.
 *
 * Here the account comes from the signed request and nothing else — there is
 * no parameter that chooses whose account is erased, so there is nothing to
 * tamper with. `confirm` must be the word `DELETE`, which is not security but
 * does stop a mis-wired client erasing somebody on a stray retry.
 *
 * The work is in {@see AccountEraser}, in one transaction.
 */
class DeleteAccountController extends Controller
{
    public function __construct(private readonly AccountEraser $eraser) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);

        if ($request->string('confirm')->toString() !== 'DELETE') {
            return LegacyEnvelope::fail('Confirmation required', 400);
        }

        $removed = $this->eraser->erase($account);

        // Row counts and an id. Never a nickname, never an address, never a
        // line of anything the account wrote.
        Log::info('account erased', ['account_id' => (int) $account->getKey(), 'removed' => $removed]);

        return LegacyEnvelope::ok(['deleted' => true]);
    }
}
