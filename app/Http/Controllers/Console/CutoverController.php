<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Entitlement;
use App\Models\Install;
use App\Models\RequestNonce;
use App\Models\SignIn;
use App\Services\Console\AccountSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * The cutover, as a number.
 *
 * This page is the thing the AA Big Book console has no equivalent of, and it
 * exists because `docs/CUTOVER.md` step 4 says to watch three figures and then
 * decide whether to roll back. Deciding that from a log file at eleven at night
 * is how the wrong decision gets made.
 *
 * Everything here is a count. It never reads a content column — the per-day
 * write rate is `COUNT(*)` grouped by a timestamp, which is exactly as much as
 * "is everybody's writing still arriving" needs.
 */
class CutoverController extends Controller
{
    public function __invoke(): View
    {
        return view('console.cutover', [
            'sealing' => $this->sealing(),
            'installs' => $this->installs(),
            'signIns' => $this->signIns(),
            'writes' => $this->writes(),
            'entitlementSources' => Entitlement::query()
                ->where('is_active', true)
                ->select('source', DB::raw('COUNT(*) AS c'))
                ->groupBy('source')
                ->pluck('c', 'source')
                ->all(),
            'replay' => $this->replay(),
            'switches' => $this->switches(),
        ]);
    }

    /**
     * How far through the cutover we are.
     *
     * An account is sealed the first time it signs in on 2.0, after which the
     * legacy protocols refuse to answer for it. So this is the only number that
     * matters for switching the old paths off: it is the proportion of accounts
     * whose weaker door is already shut, and it only ever goes up.
     */
    private function sealing(): array
    {
        $accounts = Account::query()->count();
        $sealed = Schema::hasTable('account_security')
            ? DB::table('account_security')->whereNotNull('v1_sealed_at')->count()
            : 0;

        $sealedThisWeek = Schema::hasTable('account_security')
            ? DB::table('account_security')->where('v1_sealed_at', '>=', now()->subWeek())->count()
            : 0;

        return [
            'accounts' => $accounts,
            'sealed' => $sealed,
            'remaining' => max(0, $accounts - $sealed),
            'percent' => $accounts === 0 ? 0.0 : round($sealed / $accounts * 100, 1),
            'this_week' => $sealedThisWeek,
            // At this week's rate, when is everybody through? Shown because the
            // alternative is somebody guessing, and a wrong guess here means
            // switching off a protocol people are still using.
            'weeks_left' => $sealedThisWeek > 0
                ? (int) ceil(max(0, $accounts - $sealed) / $sealedThisWeek)
                : null,
        ];
    }

    /** Which app versions are still out there, which is who a switch-off would break. */
    private function installs(): array
    {
        $rows = Install::query()
            ->where('last_seen_at', '>=', now()->subDays(30))
            ->select('platform', 'app_version', DB::raw('COUNT(*) AS c'))
            ->groupBy('platform', 'app_version')
            ->orderByDesc('c')
            ->limit(25)
            ->get();

        return [
            'seen_30_days' => (int) $rows->sum('c'),
            'rows' => $rows,
            // Installs this application has never heard from are still on the
            // old scripts; they are counted by their absence, which is the best
            // that can be done until they upgrade.
            'total_known' => Install::query()->count(),
        ];
    }

    private function signIns(): array
    {
        return [
            'week' => SignIn::query()
                ->where('created_at', '>=', now()->subWeek())
                ->select('method', DB::raw('COUNT(*) AS c'))
                ->groupBy('method')
                ->orderByDesc('c')
                ->pluck('c', 'method')
                ->all(),
            'today' => SignIn::query()->where('created_at', '>=', now()->startOfDay())->count(),
        ];
    }

    /**
     * The per-collection write rate, by day, for a fortnight.
     *
     * `docs/CUTOVER.md`: "the write rate across the six collections drops by
     * more than a tenth against the same hour the day before — roll back." This
     * is that figure, and it is a `COUNT(*)` grouped by day. Nothing is read.
     */
    private function writes(): array
    {
        $days = 14;
        $from = now()->subDays($days)->startOfDay();
        $series = [];

        foreach (array_keys(AccountSnapshot::COLLECTIONS) as $table) {
            $rows = DB::table($table)
                ->where('tstamp', '>=', $from->timestamp)
                ->selectRaw('tstamp')
                ->pluck('tstamp');

            $byDay = [];
            foreach ($rows as $ts) {
                $byDay[Carbon::createFromTimestamp((int) $ts)->toDateString()] = ($byDay[Carbon::createFromTimestamp((int) $ts)->toDateString()] ?? 0) + 1;
            }

            $series[$table] = $byDay;
        }

        // One row per day, newest first, with yesterday's comparison alongside —
        // because the number on its own says nothing and the change says
        // everything.
        $dates = collect(range(0, $days))
            ->map(fn (int $i) => now()->subDays($i)->toDateString())
            ->all();

        $table = [];
        foreach ($dates as $i => $date) {
            $total = 0;
            $perCollection = [];
            foreach ($series as $name => $byDay) {
                $n = $byDay[$date] ?? 0;
                $perCollection[$name] = $n;
                $total += $n;
            }

            $previous = $dates[$i + 1] ?? null;
            $previousTotal = $previous === null ? null : array_sum(array_map(fn (array $b) => $b[$previous] ?? 0, $series));

            $table[] = [
                'date' => $date,
                'total' => $total,
                'per_collection' => $perCollection,
                'change' => ($previousTotal === null || $previousTotal === 0)
                    ? null
                    : round(($total - $previousTotal) / $previousTotal * 100, 1),
            ];
        }

        return ['days' => $table, 'collections' => array_keys(AccountSnapshot::COLLECTIONS)];
    }

    /**
     * Proof that replay protection is doing something.
     *
     * `19/auth_checker.php` reads the nonce and throws it away, so on the live
     * server a captured request is replayable for five minutes. Here each one is
     * claimed. A nonce count that is zero while signed requests are arriving
     * means this is not working, which is worth being able to see.
     */
    private function replay(): array
    {
        return [
            'nonces_held' => RequestNonce::query()->count(),
            'retention_seconds' => (int) config('legacy.android.nonce_retention_seconds'),
            'window_seconds' => (int) config('legacy.android.hmac_window_seconds'),
        ];
    }

    /** The switches, so that nobody has to read `.env` over somebody's shoulder. */
    private function switches(): array
    {
        return [
            'v19' => (bool) config('legacy.android.enabled'),
            'v8' => (bool) config('legacy.apple.enabled'),
            'sealing' => (bool) config('legacy.seal_on_v2_login'),
            'plaintext_password' => (string) config('legacy.plaintext_password'),
            'revenuecat_bridge' => (bool) config('billing.revenuecat.enabled'),
            'sync_needs_subscription' => (bool) config('toolkit.sync.subscription_required'),
        ];
    }
}
