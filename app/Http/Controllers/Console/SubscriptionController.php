<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ConsoleAudit;
use App\Models\Entitlement;
use App\Models\StoreOrder;
use App\Models\StoreSubscription;
use App\Services\Billing\EntitlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Subscriptions, and the three ways one goes wrong.
 *
 * The filters are not a generic "filter by column" — they are the three
 * questions worth having a saved view for, because each one is money or access
 * already lost:
 *
 *  * **unattached** — a purchase with no account. Both stores let somebody buy
 *    before signing in, and the old system recorded nothing: the money was taken
 *    and no row existed. These are the people who write in saying "I paid and I
 *    have nothing".
 *  * **unmapped** — a product id this server does not recognise, so it grants
 *    nothing here. The Google ids are not confirmed
 *    (docs/OPEN_QUESTIONS.md C1), and this view is how that gets noticed rather
 *    than discovered from a one-star review.
 *  * **lapsing** — access ends within a week and will not renew.
 */
class SubscriptionController extends Controller
{
    private const VIEWS = [
        'all' => 'Everything',
        'active' => 'Granting access',
        'unattached' => 'No account attached',
        'unmapped' => 'Unknown product',
        'lapsing' => 'Ending this week',
        'refunded' => 'Refunded',
        'sandbox' => 'Sandbox',
    ];

    public function index(Request $request): View
    {
        $view = array_key_exists((string) $request->query('view'), self::VIEWS)
            ? (string) $request->query('view')
            : 'active';

        $query = StoreSubscription::query()->with('account:id,nickname,email');

        if ($request->filled('store')) {
            $query->where('store', $request->string('store')->toString());
        }

        $query = $this->applyView($query, $view);

        return view('console.subscriptions.index', [
            'views' => self::VIEWS,
            'view' => $view,
            'store' => (string) $request->query('store', ''),
            'subscriptions' => $query->orderByDesc('purchased_at')->paginate(40)->withQueryString(),
            'counts' => $this->counts(),
            'money' => $this->money(),
        ]);
    }

    /**
     * Re-ask the stores and recompute.
     *
     * The button a support person presses while somebody is on the phone saying
     * "I've resubscribed and it hasn't come back". It cannot make things worse:
     * `EntitlementService` is the most-generous-wins resolver, so a refresh can
     * only ever find more access, never less.
     */
    public function refresh(Request $request, StoreSubscription $subscription, EntitlementService $entitlements): RedirectResponse
    {
        if ($subscription->account_id !== null) {
            $entitlements->refresh((int) $subscription->account_id);
            ConsoleAudit::record('subscription.refresh', $subscription, ['account_id' => $subscription->account_id]);

            return back()->with('done', 'Recomputed account '.$subscription->account_id.'.');
        }

        return back()->with('done', 'That subscription has no account attached, so there is nothing to recompute.');
    }

    private function applyView(Builder $query, string $view): Builder
    {
        return match ($view) {
            'active' => $query->grantingAccess()->real(),
            'unattached' => $query->whereNull('account_id')->real(),
            'unmapped' => $query->real()->where(function (Builder $q): void {
                // Expressed as "not one of the known ids" rather than by calling
                // `isUnmapped()` per row, so that it is one query however many
                // subscriptions there are.
                foreach ([StoreSubscription::APPLE, StoreSubscription::GOOGLE] as $store) {
                    $known = array_keys((array) config("billing.{$store}.products", []));
                    $q->orWhere(fn (Builder $inner) => $inner->where('store', $store)->whereNotIn('product_id', $known));
                }
            }),
            'lapsing' => $query->grantingAccess()->real()
                ->where('will_renew', false)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()->addWeek()),
            'refunded' => $query->whereNotNull('refunded_at'),
            'sandbox' => $query->where('is_sandbox', true),
            default => $query,
        };
    }

    private function counts(): array
    {
        return [
            'granting' => StoreSubscription::query()->grantingAccess()->real()->count(),
            'unattached' => StoreSubscription::query()->whereNull('account_id')->real()->count(),
            'refunded' => StoreSubscription::query()->whereNotNull('refunded_at')->count(),
            'entitled' => Entitlement::query()->where('is_active', true)->count(),
            // Where the granted access is actually coming from. During the
            // overlap most of it is RevenueCat, and watching that number fall is
            // how the bridge's switch-off date gets decided rather than guessed.
            'by_source' => Entitlement::query()
                ->where('is_active', true)
                ->select('source', DB::raw('COUNT(*) AS c'))
                ->groupBy('source')
                ->pluck('c', 'source')
                ->all(),
        ];
    }

    /** Takings, in pence, from the orders this server has recorded. */
    private function money(): array
    {
        $since = now()->subDays(30);

        return [
            'thirty_days_gbp_milli' => (int) StoreOrder::query()->real()->where('purchased_at', '>=', $since)->sum('gbp_milli'),
            'thirty_days_net_gbp_milli' => (int) StoreOrder::query()->real()->where('purchased_at', '>=', $since)->sum('net_gbp_milli'),
            'thirty_days_orders' => StoreOrder::query()->real()->where('purchased_at', '>=', $since)->count(),
            'refunded_gbp_milli' => (int) StoreOrder::query()->real()->sum('refunded_milli'),
            // Said plainly rather than shown as £0: an empty figure that looks
            // like a real one is worse than no figure.
            'recorded_from' => StoreOrder::query()->real()->min('purchased_at'),
        ];
    }
}
