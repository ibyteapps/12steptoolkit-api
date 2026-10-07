@extends('console.layout', ['title' => 'Accounts'])
@section('content')
@php use App\Support\Fmt; @endphp

<div class="panel">
  <div class="spread">
    <h1>Accounts</h1>
    <span class="muted small">{{ Fmt::count($total) }} in all</span>
  </div>
  <p class="muted small">Paste whatever the person gave you: an id, an email address, the identifier from the app's About screen, or a nickname.</p>
  <form method="get" action="{{ route('console.accounts') }}">
    <div class="row" style="margin-top:8px">
      <input name="q" value="{{ $term }}" placeholder="41882, someone@example.com, 9f3c…, or a nickname" autofocus style="flex:1;min-width:260px">
      <button>Find</button>
    </div>
  </form>
</div>

@if($accounts === null)
  <p class="note">Nothing is listed until you search. This console shows how much step work an account has and when it was last written — never a word of what it says.</p>
@elseif($accounts->isEmpty())
  <div class="panel"><p>Nothing matched “{{ $term }}”.</p>
  <p class="muted small">An anonymous account has no email address, so it can only be found by id or by the identifier in the app's About screen.</p></div>
@else
  <div class="panel">
    <table>
      <thead><tr><th class="n">id</th><th>Who</th><th>Sign-in</th><th>Premium</th><th>Last seen</th><th>Joined</th></tr></thead>
      <tbody>
      @foreach($accounts as $a)
        @php $e = $a->entitlement; @endphp
        <tr>
          <td class="n"><a href="{{ route('console.accounts.show', $a->id) }}">{{ $a->id }}</a></td>
          <td>
            {{ trim((string) $a->nickname) ?: '—' }}
            <div class="muted small">{{ $a->email ?: 'no email address' }}</div>
          </td>
          <td class="small">{{ $a->devicetype === 1 ? 'iOS' : ($a->devicetype === 2 ? 'Android' : '—') }}</td>
          <td>
            @if($e && $e->is_active)
              <span class="tag {{ Fmt::stateClass($e->state) }}">{{ Fmt::label($e->state) }}</span>
              <div class="muted small">{{ Fmt::label($e->source) }}</div>
            @elseif((int) $a->subscribed === 1)
              <span class="tag">has paid before</span>
            @else
              <span class="tag">free</span>
            @endif
          </td>
          <td class="small">{{ $a->last_login_tstamp > 0 ? \Illuminate\Support\Carbon::createFromTimestamp($a->last_login_tstamp)->diffForHumans() : 'never' }}</td>
          <td class="small">{{ Fmt::when($a->legacyDate('created')) }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
  {{ $accounts->links() }}
@endif
@endsection
