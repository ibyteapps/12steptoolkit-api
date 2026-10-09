<x-my-layout title="My Toolkit">
<div class="wrap">
    @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif

    <h1>
        @php($name = trim((string) $account->getAttribute('nickname')))
        {{ $name !== '' ? 'Hello, '.$name : 'Your Step work' }}
    </h1>

    @if($sober)
        <p style="color:var(--ink-soft);margin:0 0 30px">
            Sober since {{ $sober['since']->format('j F Y') }} —
            <strong>{{ number_format($sober['days']) }}</strong>
            {{ \Illuminate\Support\Str::plural('day', $sober['days']) }}.
        </p>
    @else
        <p style="color:var(--ink-soft);margin:0 0 30px">Everything you have written, in one place.</p>
    @endif

    <div class="tiles">
        @foreach($types as $slug => $type)
            <a class="tile card" href="{{ route('my.records.index', $slug) }}">
                <span class="n">{{ number_format($counts[$slug]) }}</span>
                <span class="k">{{ $type['plural'] }}</span>
                <span class="s">{{ $type['blurb'] }}</span>
            </a>
        @endforeach
    </div>
</div>
</x-my-layout>
