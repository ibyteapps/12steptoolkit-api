<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ConsoleAudit;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Support threads.
 *
 * The one place in the console where a member's own words are shown — because a
 * support ticket *is* the member writing to support, and a reply to something
 * you cannot read is not support. The boundary holds everywhere else: the thread
 * shows what they wrote to you and never what they wrote in the app.
 *
 * The old system had no support surface at all. Mail went to an inbox, and
 * answering "has your backup been working" meant a SELECT. The link from a
 * ticket to that person's account page is the point of this screen.
 */
class SupportController extends Controller
{
    /** The states, and the order a support person wants them in. */
    private const STATES = ['open', 'answered', 'closed'];

    public function index(Request $request): View
    {
        $state = in_array($request->query('state'), self::STATES, true)
            ? (string) $request->query('state')
            : 'open';

        $tickets = SupportTicket::query()
            ->where('state', $state)
            ->withCount('messages')
            // Oldest first within a state: the person who has been waiting
            // longest is the person to answer, which is the opposite of the
            // order an inbox shows.
            ->orderBy('last_member_at')
            ->paginate(30)
            ->withQueryString();

        $sla = (int) config('console.support_sla_hours');

        return view('console.support.index', [
            'state' => $state,
            'tickets' => $tickets,
            'counts' => collect(self::STATES)
                ->mapWithKeys(fn (string $s) => [$s => SupportTicket::query()->where('state', $s)->count()])
                ->all(),
            'late' => SupportTicket::query()
                ->where('state', 'open')
                ->where('last_member_at', '<', now()->subHours($sla))
                ->count(),
            'sla' => $sla,
        ]);
    }

    public function show(string $uuid): View
    {
        $ticket = SupportTicket::query()->where('uuid', $uuid)->firstOrFail();

        ConsoleAudit::record('ticket.view', $ticket);

        return view('console.support.show', [
            'ticket' => $ticket,
            'messages' => $ticket->messages()->orderBy('created_at')->get(),
            // The link that makes this screen worth having.
            'account' => $ticket->account_id === null
                ? null
                : Account::query()->find($ticket->account_id),
        ]);
    }

    public function reply(Request $request, string $uuid): RedirectResponse
    {
        $ticket = SupportTicket::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'then' => ['nullable', Rule::in(['answered', 'closed'])],
        ]);

        (new SupportMessage)->forceFill([
            'support_ticket_id' => $ticket->id,
            'console_user_id' => auth('console')->id(),
            'from_staff' => true,
            'body' => $data['body'],
            'created_at' => now(),
        ])->save();

        $state = $data['then'] ?? 'answered';

        $ticket->forceFill([
            'state' => $state,
            'last_staff_at' => now(),
            'closed_at' => $state === 'closed' ? now() : null,
            // The member has not read the reply yet, whatever they had read
            // before it.
            'member_read_at' => null,
        ])->save();

        ConsoleAudit::record('ticket.reply', $ticket, ['state' => $state]);

        return redirect()
            ->route('console.support.show', $ticket->uuid)
            ->with('done', 'Sent.');
    }

    public function setState(Request $request, string $uuid): RedirectResponse
    {
        $ticket = SupportTicket::query()->where('uuid', $uuid)->firstOrFail();

        $state = $request->validate(['state' => ['required', Rule::in(self::STATES)]])['state'];

        $ticket->forceFill([
            'state' => $state,
            'closed_at' => $state === 'closed' ? now() : null,
        ])->save();

        ConsoleAudit::record('ticket.state', $ticket, ['state' => $state]);

        return back()->with('done', 'Moved to '.$state.'.');
    }
}
