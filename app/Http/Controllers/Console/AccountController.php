<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ConsoleAudit;
use App\Services\Console\AccountSnapshot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Finding somebody, and looking at what their account is doing.
 *
 * The old system had nothing here: answering "is my backup working" meant
 * somebody with a MySQL prompt running a SELECT against a table holding other
 * people's Fourth Steps. This page exists so that nobody has to do that again,
 * and `AccountSnapshot` is what makes sure the page cannot show what that SELECT
 * could.
 */
class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));

        return view('console.accounts.index', [
            'term' => $term,
            'accounts' => $term === '' ? null : $this->search($term),
            'total' => Account::query()->count(),
        ]);
    }

    public function show(Request $request, int $id, AccountSnapshot $snapshot): View
    {
        $account = Account::query()->with(['security', 'details', 'entitlement'])->findOrFail($id);

        // A look is recorded as well as a change. A change leaves its own trace
        // in the thing it changed; a look does not.
        ConsoleAudit::record('account.view', $account);

        return view('console.accounts.show', $snapshot->for($account) + ['raw' => $account]);
    }

    /**
     * Search by whatever the person pasted into the box.
     *
     * Support arrives as "my email is …", "my id is 41882", a UUID out of the
     * app's own About screen, or a nickname and nothing else. All four work, and
     * none of them searches a content column.
     */
    private function search(string $term): LengthAwarePaginator
    {
        $query = Account::query()
            ->select(['id', 'email', 'nickname', 'devicetype', 'sociallogin', 'subscribed', 'created', 'last_login_tstamp', 'deletion_timestamp'])
            ->with('entitlement:account_id,is_active,state,source,expires_at');

        if (ctype_digit($term)) {
            $query->where('id', (int) $term);
        } elseif (preg_match('/^[0-9a-f-]{32,36}$/i', $term)) {
            $query->whereIn('id', fn ($q) => $q->select('account_id')->from('account_security')->where('uuid', $term));
        } elseif (str_contains($term, '@')) {
            $query->whereRaw('LOWER(email) = ?', [Account::normaliseEmail($term)]);
        } else {
            // Nickname, which is the last resort because it is neither unique nor
            // indexed. Anchored rather than `%term%` so that it can use an index
            // if one is ever added, and so that a two-letter search does not
            // return a tenth of the table.
            $query->where('nickname', 'like', $term.'%');
        }

        return $query->orderByDesc('last_login_tstamp')->paginate(25)->withQueryString();
    }
}
