<x-my-layout title="Sign in">
<div class="signin">
    <div class="box card">
        <h1>Sign in</h1>
        <p class="sub">
            @if($sent)
                We sent a four-digit code to <strong>{{ $email }}</strong>.
            @else
                No password. We email you a code.
            @endif
        </p>

        @if(session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="errors">
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        @if($sent)
            <form method="POST" action="{{ route('my.sign-in.verify') }}">
                @csrf
                <label for="code">Your code</label>
                <input class="code" id="code" name="code" type="text" inputmode="numeric"
                       autocomplete="one-time-code" maxlength="4" pattern="[0-9]{4}"
                       autofocus required aria-describedby="code-help">
                <button class="primary" type="submit">Sign in</button>
            </form>
            <p class="alt" id="code-help">
                Didn't arrive?
            <form method="POST" action="{{ route('my.sign-in.request') }}" style="display:inline">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">
                <button type="submit">Send another</button>
            </form>
            </p>
        @else
            <form method="POST" action="{{ route('my.sign-in.request') }}">
                @csrf
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" autocomplete="email"
                       value="{{ old('email') }}" autofocus required>
                <button class="primary" type="submit">Email me a code</button>
            </form>
        @endif

        <p class="alt">Use the same address as the app. <a href="/">Back to the site</a></p>
    </div>
</div>
</x-my-layout>
