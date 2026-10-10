<x-site-layout
    title="Contact Us | 12 Step Toolkit Support"
    description="Questions, feedback or a support issue with the 12 Step Toolkit app? Send us a message and an A.A. member on the team will get back to you."
    :crumbs="['Contact' => null]"
>
    {{--
        The form writes `support_tickets`, which is the same queue the app's
        own support messages land in — so a message sent from here is answered
        on the same console screen. `Site\ContactController` has the reasoning
        for the honeypot, the signed token and the absence of a CSRF field.
    --}}
    <style>
        .contact { display: grid; gap: 40px; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); align-items: start; }
        @media (max-width: 820px) { .contact { grid-template-columns: 1fr; gap: 32px; } }

        .contact form { display: grid; gap: 18px; }
        .contact label { display: block; font-size: .75rem; font-weight: 700; letter-spacing: .07em;
                         text-transform: uppercase; color: var(--ink-faint); margin: 0 0 7px; }
        .contact input[type=text], .contact input[type=email], .contact textarea {
            width: 100%; font: inherit; color: var(--ink); background: var(--page);
            border: 1px solid var(--rule); border-radius: var(--r-md); padding: 12px 14px;
        }
        .contact textarea { min-height: 11rem; resize: vertical; line-height: 1.6; }
        .contact input:focus, .contact textarea:focus { border-color: var(--accent); outline: 2px solid color-mix(in srgb, var(--accent) 30%, transparent); outline-offset: 1px; }
        .contact .hint { font-size: .8125rem; color: var(--ink-faint); margin: 7px 0 0; }

        .contact button {
            justify-self: start; font: inherit; font-weight: 600; cursor: pointer;
            background: var(--brand-blue); color: #fff; border: 0;
            padding: 13px 26px; border-radius: var(--r-pill); box-shadow: var(--shadow-sm);
            transition: background .2s var(--ease), transform .2s var(--ease);
        }
        .contact button:hover { background: #2470a3; transform: translateY(-1px); }

        /* Hidden from people and from screen readers, which is the point:
           anything that fills it is not a person. */
        .contact .trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

        .notice {
            border: 1px solid var(--rule); border-radius: var(--r-md);
            padding: 16px 18px; margin: 0 0 26px; background: var(--page-alt);
        }
        .notice.good { border-color: color-mix(in srgb, var(--brand-blue) 35%, var(--rule)); background: var(--accent-soft); }
        .notice.bad { border-color: #e7c3c0; background: #fdf3f2; }
        .notice h2 { font-size: 1.0625rem; margin: 0 0 6px; }
        .notice p { margin: 0; color: var(--ink-soft); font-size: .9375rem; }
        .notice ul { margin: 6px 0 0; padding-left: 20px; color: var(--ink-soft); font-size: .9375rem; }

        .aside { border: 1px solid var(--rule); border-radius: var(--r-lg); padding: 24px; background: var(--card); box-shadow: var(--shadow-sm); }
        .aside h2 { font-size: 1.0625rem; margin: 0 0 10px; }
        .aside p { margin: 0 0 14px; color: var(--ink-soft); font-size: .9375rem; }
        .aside p:last-child { margin-bottom: 0; }
    </style>

    <h1>Get in touch with us</h1>
    <p class="lede">Questions about the app, your account or a subscription — we read
       everything that comes in.</p>

    @if ($sent)
        <div class="notice good" role="status">
            <h2>Thank you — that has reached us</h2>
            <p>Someone on the team will reply to the address you gave. If it is about a
               purchase, we may ask which store you bought through.</p>
        </div>
    @endif

    @if ($problems !== [])
        <div class="notice bad" role="alert">
            <h2>That did not send</h2>
            <ul>
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="contact">
        <form method="post" action="{{ route('site.contacts.send') }}">
            <input type="hidden" name="t" value="{{ $token }}">

            {{-- The honeypot. Never autofilled, never tabbed to, never read aloud. --}}
            <div class="trap" aria-hidden="true">
                <label for="company">Company</label>
                <input type="text" id="company" name="company" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="name">Your name <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label>
                <input type="text" id="name" name="name" value="{{ $values['name'] }}" maxlength="80" autocomplete="name">
                <p class="hint">A first name is plenty. We are an A.A. app — anonymity is the point.</p>
            </div>

            <div>
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" value="{{ $values['email'] }}" maxlength="191" autocomplete="email" required>
                <p class="hint">So we can reply. Nothing else is done with it.</p>
            </div>

            <div>
                <label for="message">How can we help?</label>
                <textarea id="message" name="message" maxlength="5000" required>{{ $values['message'] }}</textarea>
            </div>

            <button type="submit">Send message</button>
        </form>

        <div class="aside">
            <h2>Prefer email?</h2>
            <p><a href="mailto:{{ $email }}">{{ $email }}</a></p>
            <h2>About a purchase?</h2>
            <p>It helps to say which store you bought through and roughly when. We cannot
               see your card details, and we never ask for them.</p>
            <h2>Who we are</h2>
            <p>12 Step Toolkit is made by iByte Apps Limited, registered in England.</p>
        </div>
    </div>
</x-site-layout>
