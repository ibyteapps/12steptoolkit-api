<?php

namespace App\Http\Controllers\My;

use App\Services\My\RecordTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The member area's front page: sober time, and how much of each list there
 * is. Every count goes through `RecordTypes::query()`, so a count can only
 * ever be a count of the signed-in account's own rows.
 */
class HomeController extends Controller
{
    public function __invoke(): View
    {
        $account = Auth::user();
        $id = (int) $account->getKey();

        $counts = [];
        foreach (RecordTypes::all() as $slug => $type) {
            $counts[$slug] = RecordTypes::query($type, $id)->count();
        }

        return view('my.home', [
            'types' => RecordTypes::all(),
            'account' => $account,
            'counts' => $counts,
            'sober' => $this->soberSince($account->getAttribute('sobrietydate')),
        ]);
    }

    /**
     * Days sober, or null when no date is set.
     *
     * Deliberately days and not a live second counter: the apps own that, and
     * a figure that ticks is a figure somebody watches instead of reading.
     */
    private function soberSince(mixed $raw): ?array
    {
        if (blank($raw)) {
            return null;
        }

        try {
            $since = Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }

        if ($since->isFuture()) {
            return null;
        }

        return [
            'since' => $since,
            'days' => $since->diffInDays(now()),
            'years' => $since->diffInYears(now()),
        ];
    }
}
