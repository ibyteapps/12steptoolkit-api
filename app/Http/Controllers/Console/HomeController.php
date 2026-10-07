<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Entitlement;
use App\Models\SignIn;
use App\Models\StoreSubscription;
use App\Models\SupportTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * What is waiting, then what is wrong, then the week — in that order, because
 * that is the order somebody opening the console needs them in.
 *
 * "What is wrong" is deliberately second rather than buried: an unattached
 * purchase and an unrecognised product id are both money already taken for
 * something nobody received, and neither of them generates a ticket, because the
 * person affected does not know what to call it.
 */
class HomeController extends Controller
{
    public function __invoke(): View
    {
        $sla = (int) config('console.support_sla_hours');

        return view('console.home', [
            'waiting' => [
                'open' => SupportTicket::query()->where('state', 'open')->count(),
                'answered' => SupportTicket::query()->where('state', 'answered')->count(),
                'late' => SupportTicket::query()
                    ->where('state', 'open')
                    ->where('last_member_at', '<', now()->subHours($sla))
                    ->count(),
                'sla' => $sla,
            ],
            'wrong' => $this->wrong(),
            'numbers' => [
                'accounts' => Account::query()->count(),
                'premium' => Entitlement::query()->where('is_active', true)->count(),
                'joined_this_week' => Account::query()->where('created', '>=', now()->subWeek())->count(),
                'sign_ins_this_week' => SignIn::query()->where('created_at', '>=', now()->subWeek())->count(),
                'sealed' => Schema::hasTable('account_security')
                    ? DB::table('account_security')->whereNotNull('v1_sealed_at')->count()
                    : 0,
            ],
            'health' => $this->health(),
        ]);
    }

    /**
     * The things nobody will report, in the order they cost something.
     *
     * @return list<array{label: string, count: int, href: string, why: string}>
     */
    private function wrong(): array
    {
        $items = [];

        $unattached = StoreSubscription::query()->whereNull('account_id')->real()->count();
        if ($unattached > 0) {
            $items[] = [
                'label' => 'purchases with no account',
                'count' => $unattached,
                'href' => route('console.subscriptions', ['view' => 'unattached']),
                'why' => 'Money taken, nothing granted. The person does not know what to call this, so they rarely write in.',
            ];
        }

        $unmapped = StoreSubscription::query()->real()->get()
            ->filter(fn (StoreSubscription $s) => $s->isUnmapped())
            ->count();
        if ($unmapped > 0) {
            $items[] = [
                'label' => 'purchases of a product this server does not know',
                'count' => $unmapped,
                'href' => route('console.subscriptions', ['view' => 'unmapped']),
                'why' => 'Grants nothing from here. The Google product ids are unconfirmed — docs/OPEN_QUESTIONS.md C1.',
            ];
        }

        $deletions = Account::query()->where('deletion_timestamp', '>', 0)->count();
        if ($deletions > 0) {
            $items[] = [
                'label' => 'accounts that asked to be deleted',
                'count' => $deletions,
                'href' => route('console.accounts'),
                'why' => 'The stores require this to happen. If the data is still here, the erase has not run.',
            ];
        }

        return $items;
    }

    /**
     * The two things that are silently broken when they are broken.
     *
     * No scheduler means no queue worker, which means webhooks arrive and are
     * never processed — and nothing anywhere says so.
     */
    private function health(): array
    {
        $beat = Cache::get('scheduler-heartbeat');

        return [
            'scheduler' => $beat === null ? null : Carbon::parse($beat),
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'queued_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
        ];
    }
}
