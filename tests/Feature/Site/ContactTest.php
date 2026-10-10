<?php

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Site\ContactToken;
use Illuminate\Support\Facades\RateLimiter;

/*
 | The one page on the public site that writes to the database, which makes it
 | the one page where getting it wrong costs something: a form a robot can
 | post to is a table that fills up, and a form that loses what somebody typed
 | is a support message that never arrives.
 |
 | There is no session on the site routes, so these also pin the part that is
 | easy to get wrong by habit: no `old()`, no `$errors`, no CSRF field.
 */

beforeEach(function () {
    RateLimiter::clear('contact:member@example.com');
    $this->fields = fn (array $overrides = []): array => array_merge([
        't' => ContactToken::issue(),
        'name' => 'Tom',
        'email' => 'member@example.com',
        'message' => 'My subscription renewed but the app still shows the paywall.',
    ], $overrides);
});

it('shows a form with a token and no CSRF field', function () {
    $this->get('/contacts')
        ->assertOk()
        ->assertSee('name="t"', false)
        ->assertSee('How can we help?')
        // There is no session to hold one, and the controller says why.
        ->assertDontSee('name="_token"', false);
});

it('opens a ticket and keeps the message', function () {
    $this->post('/contacts', ($this->fields)())
        ->assertRedirect(route('site.contacts').'?sent=1');

    $ticket = SupportTicket::query()->sole();

    expect($ticket->email)->toBe('member@example.com')
        ->and($ticket->state)->toBe('open')
        ->and($ticket->category)->toBe('website')
        ->and($ticket->subject)->toBe('Tom — website enquiry')
        ->and($ticket->context)->toBe(['source' => 'website'])
        ->and($ticket->last_member_at)->not->toBeNull();

    $message = SupportMessage::query()->sole();

    expect($message->support_ticket_id)->toBe($ticket->id)
        ->and($message->from_staff)->toBeFalse()
        ->and($message->body)->toBe('My subscription renewed but the app still shows the paywall.');
});

it('stores no address and no user agent', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0'])
        ->post('/contacts', ($this->fields)())
        ->assertRedirect(route('site.contacts').'?sent=1');

    // `context` is for "app version, platform, locale — never content", and an
    // address is neither of those things.
    expect(json_encode(SupportTicket::query()->sole()->context))
        ->not->toContain('203.0.113.9')
        ->not->toContain('Mozilla');
});

it('shows the thank-you on the redirected page', function () {
    $this->get('/contacts?sent=1')->assertOk()->assertSee('that has reached us', false);
});

it('tells somebody what is missing without losing what they wrote', function () {
    $response = $this->post('/contacts', ($this->fields)(['email' => 'not-an-address']));

    $response->assertOk()
        ->assertSee('That did not send')
        // The words they typed come back in the textarea, from view data
        // rather than from a session that does not exist here.
        ->assertSee('still shows the paywall', false);

    expect(SupportTicket::query()->count())->toBe(0);
});

it('asks for more than a line', function () {
    $this->post('/contacts', ($this->fields)(['message' => 'help']))
        ->assertOk()
        ->assertSee('more detail would help');

    expect(SupportTicket::query()->count())->toBe(0);
});

it('answers a filled honeypot with the thank-you and writes nothing', function () {
    // Telling a robot it failed teaches it which field to leave alone.
    $this->post('/contacts', ($this->fields)(['company' => 'Acme SEO Services']))
        ->assertRedirect(route('site.contacts').'?sent=1');

    expect(SupportTicket::query()->count())->toBe(0)
        ->and(SupportMessage::query()->count())->toBe(0);
});

it('refuses a blind post with no token', function () {
    $this->post('/contacts', ($this->fields)(['t' => '']))
        ->assertOk()
        ->assertSee('had been open a while');

    expect(SupportTicket::query()->count())->toBe(0);
});

it('refuses a token that was not signed here', function () {
    $this->post('/contacts', ($this->fields)(['t' => str_repeat('a', 64)]))
        ->assertOk();

    expect(SupportTicket::query()->count())->toBe(0);
});

it('accepts last hour\'s token, so a form left open still works', function () {
    // The window is an hour and the previous one is accepted too, which is
    // what makes a page opened at 10:59 and sent at 11:01 send.
    $token = ContactToken::issue();

    $this->travel(70)->minutes();

    $this->post('/contacts', ($this->fields)(['t' => $token]))
        ->assertRedirect(route('site.contacts').'?sent=1');

    expect(SupportTicket::query()->count())->toBe(1);
});

it('stops the same address after five in an hour', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/contacts', ($this->fields)())->assertRedirect(route('site.contacts').'?sent=1');
    }

    $this->post('/contacts', ($this->fields)())->assertStatus(429);

    expect(SupportTicket::query()->count())->toBe(5);
});

it('does not rate-limit reading the page', function () {
    // A shared office address must not lose the contact page because
    // somebody else there used the form.
    for ($i = 0; $i < 8; $i++) {
        $this->get('/contacts')->assertOk();
    }
});
