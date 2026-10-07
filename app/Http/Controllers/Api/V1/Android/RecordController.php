<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\Account;
use App\Models\Amend;
use App\Models\Gratitude;
use App\Models\Inventory;
use App\Models\Journal;
use App\Models\Morning;
use App\Models\Night;
use App\Models\StepRecord;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The twelve step-work endpoints: a reader and a writer for each of the six
 * collections.
 *
 * In the live API these are twelve near-identical files of about sixty lines
 * each. They differ in the column list and in almost nothing else, and the
 * places where they differ by accident are where the bugs are — `get_journals`
 * leaves `tstamp` a string while every other reader casts it to int;
 * `morning_add_update` echoes back a fatter response than the others;
 * `amend_add_update` falls back to `strtotime($modified)` when `tstamp` is
 * missing and nothing else does. Every one of those is reproduced, because a
 * client parses them.
 *
 * What is **not** reproduced is where the account comes from. The old writers
 * take `account_id` from the POST body and rely on `AND accountid = ?` in the
 * statement to stop cross-account writes. That clause is correct and it is kept,
 * but the account itself now comes from the verified token, and a body that
 * names a different one is refused rather than silently ignored.
 */
class RecordController extends Controller
{
    /** script name → method on this class. */
    public const ENDPOINTS = [
        'get_inventories.php' => 'inventories',
        'inventory_add_update.php' => 'writeInventory',
        'get_amends.php' => 'amends',
        'amend_add_update.php' => 'writeAmend',
        'get_nights.php' => 'nights',
        'night_add_update.php' => 'writeNight',
        'get_mornings.php' => 'mornings',
        'morning_add_update.php' => 'writeMorning',
        'get_journals.php' => 'journals',
        'journal_add_update.php' => 'writeJournal',
        'get_gratitudes.php' => 'gratitudes',
        'gratitude_add_update.php' => 'writeGratitude',
    ];

    // ---------------------------------------------------------------- reads

    public function inventories(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);

        $query = Inventory::query()->ownedBy($account->id);

        // `get_inventories.php` takes `step_number` **or** `record_id` **or**
        // neither, and the ordering changes with it: by `tstamp` for a step, by
        // `id` for everything.
        if ($request->filled('record_id')) {
            $query->whereKey((int) $request->input('record_id'));
            $query->orderByDesc('id');
        } elseif ($request->filled('step_number')) {
            $query->where('inventoryforstep', (int) $request->input('step_number'))->orderByDesc('tstamp');
        } else {
            $query->orderByDesc('id');
        }

        return $this->rows($query->get(), 'Inventories fetched successfully');
    }

    public function amends(Request $request): JsonResponse
    {
        return $this->simpleRead($request, Amend::class, 'Amends fetched successfully');
    }

    public function nights(Request $request): JsonResponse
    {
        return $this->simpleRead($request, Night::class, 'Nights fetched successfully');
    }

    public function mornings(Request $request): JsonResponse
    {
        return $this->simpleRead($request, Morning::class, 'Mornings fetched successfully', recordId: false);
    }

    public function journals(Request $request): JsonResponse
    {
        return $this->simpleRead($request, Journal::class, 'Journals fetched successfully', recordId: false);
    }

    public function gratitudes(Request $request): JsonResponse
    {
        return $this->simpleRead($request, Gratitude::class, 'Gratitudes fetched successfully', recordId: false);
    }

    // --------------------------------------------------------------- writes

    public function writeInventory(Request $request): JsonResponse
    {
        return $this->write($request, Inventory::class, 'Inventory');
    }

    public function writeAmend(Request $request): JsonResponse
    {
        return $this->write($request, Amend::class, 'Amend');
    }

    public function writeNight(Request $request): JsonResponse
    {
        return $this->write($request, Night::class, 'Night');
    }

    public function writeMorning(Request $request): JsonResponse
    {
        return $this->write($request, Morning::class, 'Morning', echoColumns: true);
    }

    public function writeJournal(Request $request): JsonResponse
    {
        return $this->write($request, Journal::class, 'Journal', requireDescription: true, echoColumns: true);
    }

    public function writeGratitude(Request $request): JsonResponse
    {
        return $this->write($request, Gratitude::class, 'Gratitude', requireDescription: true, echoColumns: true);
    }

    // --------------------------------------------------------------- shared

    /** @param  class-string<StepRecord>  $model */
    private function simpleRead(Request $request, string $model, string $message, bool $recordId = true): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);
        $query = $model::query()->ownedBy($account->id);

        if ($recordId && $request->filled('record_id')) {
            $query->whereKey((int) $request->input('record_id'));
        }

        return $this->rows($query->orderByDesc($model::orderColumn())->get(), $message);
    }

    private function rows(iterable $records, string $message): JsonResponse
    {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = $record->toLegacyRow();
        }

        return LegacyEnvelope::ok($rows, $message);
    }

    /**
     * The one upsert, for all six.
     *
     * @param  class-string<StepRecord>  $model
     * @param  bool  $requireDescription  journals and gratitudes refuse an empty body
     * @param  bool  $echoColumns  some writers echo the written columns back
     */
    private function write(
        Request $request,
        string $model,
        string $noun,
        bool $requireDescription = false,
        bool $echoColumns = false,
    ): JsonResponse {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $account = $this->account($request);

        $localId = (string) $request->input('id', '');
        $onlineId = (int) $request->input('online_id', 0);
        $isDeleted = (int) $request->input('is_deleted', 0);

        if ($requireDescription && trim((string) $request->input('description', '')) === '') {
            // `journal_add_update.php:16` refuses this, and so does the client.
            // Refusing here is what stops a row being written, queued, rejected
            // and parked in an outbox nobody looks at.
            return LegacyEnvelope::fail('Description is required', 400);
        }

        if ($onlineId > 0 && $isDeleted === 1) {
            $deleted = $model::query()->ownedBy($account->id)->whereKey($onlineId)->delete();

            return $this->writeResponse($localId, $onlineId, $account, $isDeleted, 'DELETE', $noun, $deleted > 0 ? [] : null);
        }

        $values = $this->values($request, $model, $account);

        if ($onlineId > 0) {
            $existing = $model::query()->ownedBy($account->id)->whereKey($onlineId)->first();
            if ($existing === null) {
                // The row is gone — deleted on another device. The old code
                // would report success for an UPDATE that matched nothing; this
                // says so, because the client needs to know to stop retrying.
                return LegacyEnvelope::fail($noun.' not found', 404);
            }
            $existing->forceFill($values + ['modified' => now()])->save();

            return $this->writeResponse($localId, $onlineId, $account, $isDeleted, 'UPDATE', $noun, $echoColumns ? $values : null);
        }

        $id = DB::transaction(function () use ($model, $values, $account): int {
            $record = new $model;
            $record->forceFill($values + [
                'accountid' => $account->id,
                'created' => now(),
                'modified' => now(),
            ])->save();

            return (int) $record->getKey();
        });

        return $this->writeResponse($localId, $id, $account, $isDeleted, 'ADD', $noun, $echoColumns ? $values : null);
    }

    /**
     * Reads the writable columns out of the request, casting each one the way
     * the old `bind_param` type string did.
     */
    private function values(Request $request, string $model, Account $account): array
    {
        $values = [];

        foreach ($model::writableColumns() as $column => $type) {
            if (! $request->has($column)) {
                continue;
            }
            $raw = $request->input($column);
            $values[$column] = match ($type) {
                'int' => (int) $raw,
                'switch' => Night::normaliseSwitch($raw),
                default => (string) $raw,
            };
        }

        // `tstamp` is the sort key on every list. The old writers each defaulted
        // it differently; the common case is "now", and `amend_add_update.php`'s
        // fallback to `strtotime($modified)` is kept where the client sends one.
        if (! array_key_exists('tstamp', $values) || $values['tstamp'] <= 0) {
            $modified = (string) $request->input('modified', '');
            $fromModified = $modified === '' ? false : strtotime($modified);
            $values['tstamp'] = $fromModified === false ? time() : $fromModified;
        }

        return $values;
    }

    private function writeResponse(
        string $localId,
        int $onlineId,
        Account $account,
        int $isDeleted,
        string $action,
        string $noun,
        ?array $extra,
    ): JsonResponse {
        $response = LegacyEnvelope::strings([
            'id' => $localId,
            'online_id' => $onlineId,
            'account_id' => $account->id,
            'is_deleted' => $isDeleted,
            'action' => $action,
        ] + ($extra ?? []));

        return LegacyEnvelope::ok($response, $noun.' '.$action.' successful');
    }
}
