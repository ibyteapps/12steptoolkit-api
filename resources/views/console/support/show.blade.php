@extends('console.layout', ['title' => $ticket->subject])
@section('content')
@php use App\Support\Fmt; @endphp

<div class="panel">
  <div class="spread">
    <div>
      <h1>{{ $ticket->subject }}</h1>
      <p class="muted small" style="margin:0">
        <span class="tag">{{ Fmt::label($ticket->category) }}</span>
        <span class="tag {{ $ticket->state === 'open' ? 'warn' : '' }}">{{ $ticket->state }}</span>
        · opened {{ Fmt::when($ticket->created_at) }}
        @if($ticket->email) · {{ $ticket->email }} @endif
      </p>
    </div>
    @if($account)
      <a href="{{ route('console.accounts.show', $account->id) }}">Their account ({{ $account->id }}) →</a>
    @endif
  </div>

  @if($ticket->context)
    <p class="muted small" style="margin:12px 0 0">
      @foreach($ticket->context as $k => $v)<span class="tag">{{ $k }}: {{ is_scalar($v) ? $v : json_encode($v) }}</span> @endforeach
    </p>
  @endif
  @unless($account)
    <p class="note" style="margin-top:12px">Nobody is signed in on this ticket, so there is no account to look at. If they tell you their id or the identifier from the app's About screen, <a href="{{ route('console.accounts') }}">search for it</a>.</p>
  @endunless
</div>

<div class="panel">
  <h2>The thread</h2>
  @forelse($messages as $m)
    <div class="msg {{ $m->from_staff ? 'staff' : '' }}">
      <div class="muted small">{{ $m->from_staff ? 'Support' : 'Them' }} · {{ Fmt::exact($m->created_at) }}</div>
      <pre>{{ $m->body }}</pre>
    </div>
  @empty
    <p class="muted">Nothing yet.</p>
  @endforelse
</div>

<div class="panel">
  <h2>Reply</h2>
  <form method="post" action="{{ route('console.support.reply', $ticket->uuid) }}">
    @csrf
    <textarea name="body" placeholder="Write it as you would to a person, not a ticket." required>{{ old('body') }}</textarea>
    @error('body')<p class="error">{{ $message }}</p>@enderror
    <div class="row" style="margin-top:12px">
      <button name="then" value="answered">Send</button>
      <button name="then" value="closed" class="quiet">Send and close</button>
    </div>
  </form>
</div>

<div class="panel">
  <h3>Move it without replying</h3>
  <form method="post" action="{{ route('console.support.state', $ticket->uuid) }}" class="row">
    @csrf
    @foreach(['open' => 'Back to waiting', 'answered' => 'Answered', 'closed' => 'Closed'] as $key => $label)
      @if($ticket->state !== $key)<button name="state" value="{{ $key }}" class="quiet">{{ $label }}</button>@endif
    @endforeach
  </form>
</div>
@endsection
