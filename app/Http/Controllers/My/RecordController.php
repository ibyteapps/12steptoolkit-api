<?php

namespace App\Http\Controllers\My;

use App\Services\My\RecordFields;
use App\Services\My\RecordTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reading and removing one's own records.
 *
 * The contrast with what this replaces is the whole point. `getlist_working.php`
 * took an account id from the request body; `deleterecord.php` took a row id
 * and no account at all, so any id deleted any row. Here the account is
 * `Auth::id()` from a signed session cookie, every query starts from
 * `RecordTypes::query()` which cannot be called without it, and a row that
 * belongs to somebody else is a 404 — not a 403, which would confirm it
 * exists.
 */
class RecordController extends Controller
{
    public function index(Request $request, string $slug): View
    {
        $type = $this->type($slug);

        $records = RecordTypes::query($type, (int) Auth::id())
            ->paginate((int) config('my.per_page'))
            ->withQueryString();

        return view('my.records.index', [
            'slug' => $slug,
            'type' => $type,
            'records' => $records,
        ]);
    }

    public function show(string $slug, int $id): View
    {
        $type = $this->type($slug);

        $record = $this->own($type, $id);

        return view('my.records.show', [
            'slug' => $slug,
            'type' => $type,
            'record' => $record,
            'fields' => $this->readableFields($type, $record),
        ]);
    }

    public function create(string $slug): View
    {
        $type = $this->type($slug);

        return view('my.records.form', [
            'slug' => $slug,
            'type' => $type,
            'record' => null,
            'fields' => RecordFields::for($slug),
            'values' => [],
        ]);
    }

    public function edit(string $slug, int $id): View
    {
        $type = $this->type($slug);
        $record = $this->own($type, $id);

        return view('my.records.form', [
            'slug' => $slug,
            'type' => $type,
            'record' => $record,
            'fields' => RecordFields::for($slug),
            'values' => $this->currentValues($slug, $record),
        ]);
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        $type = $this->type($slug);
        $model = $type['model'];

        $record = new $model;
        $record->forceFill($this->writable($request, $slug) + [
            // The account is taken from the session, never from the form. A
            // posted accountid is ignored because it is not in the whitelist.
            'accountid' => (int) Auth::id(),
            'tstamp' => time(),
        ] + $type['where']);
        $record->save();

        return redirect()->route('my.records.show', [$slug, $record->getKey()])
            ->with('status', 'Saved.');
    }

    public function update(Request $request, string $slug, int $id): RedirectResponse
    {
        $type = $this->type($slug);
        $record = $this->own($type, $id);

        $record->forceFill($this->writable($request, $slug));
        $record->save();

        return redirect()->route('my.records.show', [$slug, $id])->with('status', 'Saved.');
    }

    public function destroy(string $slug, int $id): RedirectResponse
    {
        $type = $this->type($slug);

        // The delete is a query, not a lookup followed by a delete: the
        // account is in the WHERE clause, so a row belonging to someone else
        // matches nothing rather than being found and then refused.
        $deleted = RecordTypes::query($type, (int) Auth::id())->whereKey($id)->delete();

        if ($deleted === 0) {
            throw new NotFoundHttpException;
        }

        return redirect()->route('my.records.index', $slug)
            ->with('status', 'Deleted.');
    }

    /**
     * The values a form posted, reduced to the columns this type publishes.
     *
     * Built by walking the field definitions, not by reading the request — so
     * a form that posts `accountid`, `shared` or `reviewed` writes none of
     * them, whatever the browser sends.
     */
    private function writable(Request $request, string $slug): array
    {
        $out = [];

        foreach (RecordFields::for($slug) as $field) {
            $column = $field['column'];
            $input = $request->input('f.'.$column);

            $out[$column] = match ($field['kind']) {
                // The legacy columns hold the words, not booleans.
                'switch' => $request->boolean('f.'.$column) ? 'Yes' : 'No',
                'done' => $request->boolean('f.'.$column) ? 1 : 0,
                'tags' => implode(', ', array_values(array_intersect(
                    $field['options'],
                    (array) $request->input('f.'.$column, []),
                ))),
                'mood' => in_array($input, $field['options'], true) ? (string) $input : '',
                default => trim((string) $input),
            };
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function currentValues(string $slug, $record): array
    {
        $values = [];

        foreach (RecordFields::for($slug) as $field) {
            $raw = (string) ($record->getRawOriginal($field['column']) ?? '');
            $values[$field['column']] = match ($field['kind']) {
                'switch' => strcasecmp($raw, 'Yes') === 0,
                'done' => (int) $raw === 1,
                'tags' => array_filter(array_map('trim', explode(',', $raw))),
                default => $raw,
            };
        }

        return $values;
    }

    /** One of the member's own records, or a 404. */
    private function own(array $type, int $id)
    {
        $record = RecordTypes::query($type, (int) Auth::id())->find($id);

        if ($record === null) {
            throw new NotFoundHttpException;
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function type(string $slug): array
    {
        $type = RecordTypes::find($slug);

        if ($type === null) {
            throw new NotFoundHttpException;
        }

        return $type;
    }

    /**
     * The record's own columns, labelled, with the empty ones dropped.
     *
     * Driven by the model's `projection()` so a column the model does not
     * publish is a column this page cannot print.
     */
    private function readableFields(array $type, $record): array
    {
        $labels = [
            'invtitle' => 'Title', 'invdescription' => 'The cause', 'affectsmy' => 'Affects my',
            'myfault' => 'My part', 'apologynotes' => 'Notes', 'apologydate' => 'Apology made',
            'amendstitle' => 'Amends to', 'amendsfor' => 'For', 'amendsnotes' => 'Notes',
            'amendsdate' => 'Amends made', 'description' => 'Entry', 'q6_notes' => 'Notes',
            'thedate' => 'Date', 'timestamp' => 'Written',
        ];

        $out = [];
        foreach (array_keys($record::projection()) as $column) {
            // The title is the page's heading; printing it again as a field
            // under the heading reads like a bug.
            if ($column === $type['title'] || ! array_key_exists($column, $labels)) {
                continue;
            }
            $value = (string) ($record->getRawOriginal($column) ?? '');
            if (trim($value) !== '') {
                $out[$labels[$column]] = $value;
            }
        }

        return $out;
    }
}
