<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ConsoleAudit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Who did what.
 *
 * Visible to everybody who can sign in, including the records of their own
 * actions. A log that only an administrator can read is a log people assume
 * nobody reads.
 */
class AuditController extends Controller
{
    public function __invoke(Request $request): View
    {
        $query = ConsoleAudit::query()->with('consoleUser:id,name');

        if ($request->filled('action')) {
            $query->where('action', 'like', $request->string('action')->toString().'%');
        }

        if ($request->filled('who')) {
            $query->where('console_user_id', $request->integer('who'));
        }

        return view('console.audit', [
            'entries' => $query->orderByDesc('created_at')->paginate(60)->withQueryString(),
            'actions' => ConsoleAudit::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
