<?php

namespace App\Http\Controllers\Site;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Site\ContactToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The contact form, which opens a support ticket.
 *
 * The static site's form posted to a third-party form service. This one
 * writes `support_tickets` and `support_messages`, which is where the console
 * already reads from — so a message from the website and a message from the
 * app land in the same queue and get answered the same way.
 *
 * ## The three spam defences, and what each is for
 *
 *  * **A honeypot** (`company`), hidden from people and from screen readers,
 *    which a form-filling robot completes because it completes everything.
 *    A filled honeypot is answered with the same thank-you as a real message
 *    and writes nothing: a robot that is told it failed tries again.
 *  * **A signed token** from {@see ContactToken} — the sender has to have
 *    fetched the page, and the value expires. Not a CSRF token; that class
 *    says why.
 *  * **A rate limit** of five an hour per address, defined as
 *    `site-contact` in `AppServiceProvider`.
 *
 * ## No session, so no flash
 *
 * The site routes carry no session (`bootstrap/app.php`), so the usual
 * redirect-back-with-errors does not exist here: `old()` and `$errors` both
 * read the session. Validation failures therefore re-render the page with the
 * problems and the values passed as plain view data, and a success redirects
 * to `?sent=1` so that a refresh does not post the message twice.
 *
 * ## What is not stored
 *
 * No IP address and no user agent. The `context` column is for "app version,
 * platform, locale — never content", and a support message from a recovery
 * app is about as sensitive as text gets: it is written once, to the row the
 * person asked us to read, and it is never logged, never flashed (see
 * `dontFlash` in `bootstrap/app.php`) and never put in a URL.
 */
class ContactController extends Controller
{
    private const CATEGORY = 'website';

    public function show(Request $request): View
    {
        return view('site.pages.contacts', [
            'email' => config('toolkit.support.email', 'support@12steptoolkit.com'),
            'token' => ContactToken::issue(),
            'sent' => $request->query('sent') === '1',
            'problems' => [],
            'values' => ['name' => '', 'email' => '', 'message' => ''],
        ]);
    }

    public function store(Request $request): View|RedirectResponse
    {
        // A robot that fills every field gets the thank-you page and nothing
        // else happens. Answering 422 here would only teach it which field to
        // leave alone.
        if (trim((string) $request->input('company', '')) !== '') {
            return redirect()->to(route('site.contacts').'?sent=1');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:191'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [
            'email.required' => 'We need an address to reply to.',
            'email.email' => 'That address does not look right — we would not be able to reply.',
            'message.required' => 'Tell us what has happened and we will take a look.',
            'message.min' => 'A little more detail would help us answer properly.',
        ]);

        if (! ContactToken::valid((string) $request->input('t', ''))) {
            // An expired form rather than a hostile one, nearly always: the
            // page was left open for hours. Say so, and the re-render carries
            // a fresh token and the words they typed.
            $validator->after(fn ($v) => $v->errors()->add(
                'message',
                'This form had been open a while and the page has refreshed it — please send that again.',
            ));
        }

        if ($validator->fails()) {
            return view('site.pages.contacts', [
                'email' => config('toolkit.support.email', 'support@12steptoolkit.com'),
                'token' => ContactToken::issue(),
                'sent' => false,
                'problems' => $validator->errors()->all(),
                'values' => [
                    'name' => (string) $request->input('name', ''),
                    'email' => (string) $request->input('email', ''),
                    'message' => (string) $request->input('message', ''),
                ],
            ]);
        }

        $this->open(
            email: strtolower(trim((string) $request->input('email'))),
            name: trim((string) $request->input('name', '')),
            body: (string) $request->input('message'),
        );

        return redirect()->to(route('site.contacts').'?sent=1');
    }

    /** The ticket and its first message, together or not at all. */
    private function open(string $email, string $name, string $body): void
    {
        DB::transaction(function () use ($email, $name, $body): void {
            $ticket = SupportTicket::query()->create([
                'uuid' => (string) Str::uuid(),
                'email' => $email,
                // The subject a member would have written themselves. The old
                // form had no subject field and the console lists by subject,
                // so this is the one thing composed here rather than typed.
                'subject' => $name !== '' ? $name.' — website enquiry' : 'Website enquiry',
                'category' => self::CATEGORY,
                'state' => 'open',
                // Never content, and never an address or a user agent. Which
                // form it came through is the whole of it.
                'context' => ['source' => 'website'],
                'last_member_at' => now(),
            ]);

            SupportMessage::query()->create([
                'support_ticket_id' => $ticket->id,
                'from_staff' => false,
                'body' => $body,
                'created_at' => now(),
            ]);
        });
    }
}
