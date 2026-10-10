<?php

namespace App\Services\Sponsorship;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * What a sponsor sees about a sponsee's Step work: counts, never content.
 *
 * `get_sponsee_steps_data.php` is the one endpoint in the sponsorship family
 * that answers about **another person's** records, and the only thing that
 * makes that acceptable is that it answers in numbers. Nothing here returns a
 * resentment, an amend or a nightly review — only how many there are and how
 * many are waiting for the sponsor.
 *
 * ## The two spellings
 *
 * The live script answers `step_dates.comments` keyed `step2`, `step3`,
 * `step6`, `step7`, and `amends.pending_amends`. The client reads
 * `step_dates.comments.step4`, `…step89`, `…step10`, `…step11` and
 * `amends.pending`, so on the live server those four comment counts and the
 * amends figure are always zero. Both spellings are answered here: the live
 * ones so nothing that reads them breaks, and the client's so its screen
 * stops showing zeros it should not show. Counting comments for 4, 10, 89 and
 * 11 is the same query with a different step number.
 */
class SponseeOverview
{
    /** The steps the live script counts comments for, plus the ones the client asks about. */
    private const COMMENT_STEPS = [1, 2, 3, 4, 6, 7, 10, 11, 89];

    /** @return array<string, mixed> */
    public function for(int $sponseeId, int $sponsorId): array
    {
        $account = Account::query()->find($sponseeId);
        $comments = $this->comments($sponseeId, $sponsorId);

        $step4 = $this->inventories($sponseeId, 4);
        $step10 = $this->spotChecks($sponseeId);
        $amends = $this->amends($sponseeId);
        $nights = $this->nights($sponseeId);

        return [
            'sobriety' => [
                'date' => (string) ($account?->getRawOriginal('sobrietydate') ?? ''),
                'time' => (string) ($account?->getRawOriginal('sobrietytime') ?? ''),
                'comments_count' => $comments[1],
            ],
            'step_dates' => [
                'step2' => (string) ($account?->getRawOriginal('step2') ?? ''),
                'step3' => (string) ($account?->getRawOriginal('step3') ?? ''),
                'step6' => (string) ($account?->getRawOriginal('step6') ?? ''),
                'step7' => (string) ($account?->getRawOriginal('step7') ?? ''),
                'comments' => [
                    'step2' => $comments[2],
                    'step3' => $comments[3],
                    'step6' => $comments[6],
                    'step7' => $comments[7],
                    // What the client reads, and the live script never sends.
                    'step4' => $comments[4],
                    'step10' => $comments[10],
                    'step11' => $comments[11],
                    'step89' => $comments[89],
                ],
            ],
            'step4' => [
                'total' => $step4['total'],
                'pending_share' => $step4['pending_share'],
                'pending_review' => $step4['pending_review'],
            ],
            'step10' => [
                'total' => $step10['total'],
                'pending_apologies' => $step10['pending_apologies'],
                'pending_review' => $step10['pending_review'],
            ],
            'amends' => [
                'total' => $amends['total'],
                'pending_amends' => $amends['pending'],
                // The client's spelling.
                'pending' => $amends['pending'],
                'pending_review' => $amends['pending_review'],
            ],
            'nights' => [
                'total' => $nights['total'],
                'pending_today' => $nights['pending_today'],
                'pending_review' => $nights['pending_review'],
            ],
        ];
    }

    /** @return array<int, int> */
    private function comments(int $sponseeId, int $sponsorId): array
    {
        $counts = array_fill_keys(self::COMMENT_STEPS, 0);

        if ($sponsorId <= 0) {
            return $counts;
        }

        $rows = DB::table('comments')
            ->where('sponsorid', $sponsorId)
            ->where('accountid', $sponseeId)
            ->whereIn('step', self::COMMENT_STEPS)
            ->groupBy('step')
            ->select(['step', DB::raw('COUNT(*) as total')])
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->step] = (int) $row->total;
        }

        return $counts;
    }

    /** @return array{total: int, pending_share: int, pending_review: int} */
    private function inventories(int $accountId, int $step): array
    {
        $row = DB::table('inventories')
            ->where('accountid', $accountId)
            ->where('inventoryforstep', $step)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN shared = 0 THEN 1 ELSE 0 END) as pending_share')
            ->selectRaw('SUM(CASE WHEN reviewed = 0 THEN 1 ELSE 0 END) as pending_review')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'pending_share' => (int) ($row->pending_share ?? 0),
            'pending_review' => (int) ($row->pending_review ?? 0),
        ];
    }

    /**
     * @return array{total: int, pending_apologies: int, pending_review: int}
     *
     * The live script sums `apologyowed - apologydone` across the rows, so a
     * row where more apologies were made than were owed subtracts from
     * somebody else's count and two of them can hide a third. This counts
     * rows that still owe one.
     */
    private function spotChecks(int $accountId): array
    {
        $row = DB::table('inventories')
            ->where('accountid', $accountId)
            ->where('inventoryforstep', 10)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN apologyowed > apologydone THEN 1 ELSE 0 END) as pending_apologies')
            ->selectRaw('SUM(CASE WHEN reviewed = 0 THEN 1 ELSE 0 END) as pending_review')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'pending_apologies' => (int) ($row->pending_apologies ?? 0),
            'pending_review' => (int) ($row->pending_review ?? 0),
        ];
    }

    /** @return array{total: int, pending: int, pending_review: int} */
    private function amends(int $accountId): array
    {
        $row = DB::table('amends')
            ->where('accountid', $accountId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN amendsdone = 0 THEN 1 ELSE 0 END) as pending')
            ->selectRaw('SUM(CASE WHEN reviewed = 0 THEN 1 ELSE 0 END) as pending_review')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'pending_review' => (int) ($row->pending_review ?? 0),
        ];
    }

    /**
     * @return array{total: int, pending_today: int, pending_review: int}
     *
     * `nights.tstamp` is an epoch integer and the live script compares
     * `DATE(tstamp)` with today's date — `DATE(1760000000)` is not a date, so
     * "written today" is false for everybody, every day, and every sponsor
     * sees every sponsee as having skipped tonight. This compares the day's
     * range in seconds.
     */
    private function nights(int $accountId): array
    {
        $row = DB::table('nights')
            ->where('accountid', $accountId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN reviewed = 0 THEN 1 ELSE 0 END) as pending_review')
            ->first();

        $writtenToday = DB::table('nights')
            ->where('accountid', $accountId)
            ->whereBetween('tstamp', [now()->startOfDay()->getTimestamp(), now()->endOfDay()->getTimestamp()])
            ->exists();

        return [
            'total' => (int) ($row->total ?? 0),
            'pending_today' => $writtenToday ? 0 : 1,
            'pending_review' => (int) ($row->pending_review ?? 0),
        ];
    }
}
