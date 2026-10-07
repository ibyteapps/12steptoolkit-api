<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ComplimentaryGrant;
use App\Models\ConsoleAudit;
use App\Services\Billing\EntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Giving somebody a subscription, and taking it back.
 *
 * The old system's equivalent was `accounts.free_upgrade`, a tinyint with no
 * reason, no author, no start and no end — so nobody could answer "why does this
 * person have premium" or "when does it stop", and the only way to remove it was
 * another UPDATE by hand.
 *
 * A grant here has all four, stacks with whatever the stores say, and is revoked
 * rather than deleted, because somebody gave it and somebody took it away and
 * both are worth being able to find later.
 */
class GrantController extends Controller
{
    public function store(Request $request, int $accountId, EntitlementService $entitlements): RedirectResponse
    {
        $account = Account::query()->findOrFail($accountId);

        $data = $request->validate([
            'period' => ['required', Rule::in(array_keys(ComplimentaryGrant::PERIODS))],
            // Required, not optional: a grant with no reason is the thing that
            // made `free_upgrade` useless.
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $starts = now();

        $grant = new ComplimentaryGrant;
        $grant->forceFill([
            'account_id' => $account->id,
            'period' => $data['period'],
            'reason' => $data['reason'],
            'granted_by' => auth('console')->id(),
            'starts_at' => $starts,
            'ends_at' => ComplimentaryGrant::endFor($data['period'], $starts),
        ])->save();

        $entitlements->refresh((int) $account->id);

        ConsoleAudit::record('grant.create', $grant, [
            'account_id' => $account->id,
            'period' => $data['period'],
        ]);

        return back()->with('done', ComplimentaryGrant::PERIODS[$data['period']].' given to account '.$account->id.'.');
    }

    public function destroy(Request $request, int $accountId, ComplimentaryGrant $grant, EntitlementService $entitlements): RedirectResponse
    {
        abort_unless((int) $grant->account_id === $accountId, 404);

        if ($grant->revoked_at === null) {
            $grant->forceFill(['revoked_at' => now()])->save();
            $entitlements->refresh($accountId);
            ConsoleAudit::record('grant.revoke', $grant, ['account_id' => $accountId]);
        }

        return back()->with('done', 'That grant has been revoked.');
    }
}
