{{--
    The static page carried a contact form that posted off-site. It is not
    reproduced here yet on purpose: this application already has
    `support_tickets` and a console screen for answering them, so the right
    version of this form opens a ticket rather than sending an email — and a
    public form that writes to the database needs its rate limit and its
    honeypot decided rather than assumed. Until then the page gives the
    address, which is what the form was for.
--}}
<x-site-layout
    title="Contact Us | 12 Step Toolkit Support"
    description="Questions, feedback or a support issue with the 12 Step Toolkit app? Send us a message and an A.A. member on the team will get back to you."
    :crumbs="['Contact' => null]"
>
    <h1>Get in touch with us</h1>
    <p class="lede">Questions about the app, your account or a subscription — we read
       everything that comes in.</p>

    <article class="reading prose">
        <p><strong>Email:</strong> <a href="mailto:{{ $email }}">{{ $email }}</a></p>
        <p>If you are writing about a purchase, it helps to say which store you
           bought through and roughly when.</p>
        <p>12 Step Toolkit is made by iByte Apps Limited, registered in England.</p>
    </article>

    <p class="stores">
        <a href="https://apps.apple.com/gb/app/12-step-toolkit/id1452072215">App Store</a>
        <a href="https://play.google.com/store/apps/details?id=com.ibyteapps.aa12steptoolkit">Google Play</a>
    </p>
</x-site-layout>
