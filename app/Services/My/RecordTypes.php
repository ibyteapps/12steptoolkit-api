<?php

namespace App\Services\My;

use App\Models\Amend;
use App\Models\Gratitude;
use App\Models\Inventory;
use App\Models\Journal;
use App\Models\Morning;
use App\Models\Night;
use App\Models\StepRecord;
use Carbon\CarbonImmutable;

/**
 * The seven lists the member area shows, and what each one is.
 *
 * The old web app carried this as `typeMapping` — a bare slug-to-integer map
 * whose integers were posted to the server and turned back into a table name
 * by a switch statement. The integer never had to match the account asking,
 * which is how `deleterecord.php` came to delete any row by id.
 *
 * Here the slug picks a model class and a scope, and the account comes from
 * the session. A slug that is not in this list is a 404 before any query
 * runs, so the set of readable tables is this file rather than whatever a
 * caller puts in the body.
 */
final class RecordTypes
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'step-4' => [
                'label' => 'Step Four inventory',
                'plural' => 'Step Four inventories',
                'blurb' => 'Resentments, fears and harms — the searching and fearless moral inventory.',
                'model' => Inventory::class,
                'where' => ['inventoryforstep' => 4],
                'title' => 'invtitle',
                'summary' => 'invdescription',
            ],
            'step-10' => [
                'label' => 'Spot check inventory',
                'plural' => 'Spot check inventories',
                'blurb' => 'Step Ten — continued to take personal inventory.',
                'model' => Inventory::class,
                'where' => ['inventoryforstep' => 10],
                'title' => 'invtitle',
                'summary' => 'invdescription',
            ],
            'amends' => [
                'label' => 'Amends',
                'plural' => 'Amends',
                'blurb' => 'Step Eight — the list of all persons we had harmed.',
                'model' => Amend::class,
                'where' => [],
                'title' => 'amendstitle',
                'summary' => 'amendsfor',
                'done' => 'amendsdone',
            ],
            'nightly' => [
                'label' => 'Nightly inventory',
                'plural' => 'Nightly inventories',
                'blurb' => 'The questions asked at the end of the day.',
                'model' => Night::class,
                'where' => [],
                'title' => null,
                'summary' => null,
            ],
            'morning' => [
                'label' => 'Morning inventory',
                'plural' => 'Morning inventories',
                'blurb' => 'The check-in on waking.',
                'model' => Morning::class,
                'where' => [],
                'title' => null,
                'summary' => 'q6_notes',
            ],
            'journals' => [
                'label' => 'Journal entry',
                'plural' => 'Journal',
                'blurb' => 'Whatever is worth writing down.',
                'model' => Journal::class,
                'where' => [],
                'title' => null,
                'summary' => 'description',
            ],
            'gratitude' => [
                'label' => 'Gratitude list',
                'plural' => 'Gratitude lists',
                'blurb' => 'What there is to be grateful for today.',
                'model' => Gratitude::class,
                'where' => [],
                'title' => null,
                'summary' => 'description',
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /**
     * When a record was written, in words.
     *
     * The legacy tables carry three overlapping stamps — `created`, `modified`
     * and a unix `tstamp` — and which of them is populated varies by table and
     * by how old the row is. `created` comes first because the date that
     * matters on a piece of Step work is when it was written, not when it was
     * last touched; this takes the first that parses rather than trusting any
     * single one, and says nothing at all when none do, because an invented
     * date on somebody's Step Four is worse than no date.
     */
    public static function writtenOn(StepRecord $record): string
    {
        foreach (['created', 'modified'] as $column) {
            $value = $record->getRawOriginal($column);
            if (filled($value) && $value !== '0000-00-00 00:00:00') {
                try {
                    return CarbonImmutable::parse((string) $value)->format('j F Y');
                } catch (\Throwable) {
                    // fall through to the next candidate
                }
            }
        }

        $stamp = (int) $record->getRawOriginal('tstamp');

        return $stamp > 0 ? CarbonImmutable::createFromTimestamp($stamp)->format('j F Y') : '';
    }

    /**
     * A query for one type, already scoped to one account.
     *
     * There is no way to call this without an account id, which is the whole
     * point: `ownedBy()` is not an option the caller may leave off.
     */
    public static function query(array $type, int $accountId)
    {
        /** @var class-string<StepRecord> $model */
        $model = $type['model'];

        return $model::query()
            ->ownedBy($accountId)
            ->where($type['where'])
            ->orderByDesc($model::orderColumn())
            ->orderByDesc('id');
    }
}
