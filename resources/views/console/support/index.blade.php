@extends('console.layout', ['title' => 'Support'])
@section('content')
@php use App\Support\Fmt; @endphp

<div class="panel">
  <div class="spread">
    <h1>Support</h1>
    @if($late > 0)<span class="tag bad">{{ $late }} waiting longer than {{ $sla }} hours</span>@endif
  </div>
  <p class="muted small">Oldest first — the person who has been waiting longest is the person to answer, which is the opposite of the order an inbox shows you.</p>
</div>

<div class="pills">
  @foreach(['open' => 'Waiting', 'answered' => 'Answered', 'closed' => 'Closed'] as $key => $label)
    <a href="{{ route('console.support', ['state' => $key]) }}" @if($state === $key) aria-current="page" @endif>{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
  @endforeach
</div>

<div class="panel">
  @if($tickets->isEmpty())
    <p class="muted">Nothing {{ $state === 'open' ? 'waiting' : $state }}.</p>
  @else
    <table>
      <thead><tr><th>Subject</th><th>Who</th><th>About</th><th class="n">Messages</th><th>Waiting since</th></tr></thead>
      <tbody>
      @foreach($tickets as $t)
        @php $isLate = $t->state === 'open' && $t->last_member_at && $t->last_member_at->lt(now()->subHours($sla)); @endphp
        <tr>
          <td><a href="{{ route('console.support.show', $t->uuid) }}">{{ $t->subject }}</a></td>
          <td class="small">
            @if($t->account_id)<a href="{{ route('console.accounts.show', $t->account_id) }}">{{ $t->account_id }}</a>@else<span class="muted">not signed in</span>@endif
            <div class="muted">{{ $t->email ?: '' }}</div>
          </td>
          <td><span class="tag">{{ Fmt::label($t->category) }}</span></td>
          <td class="n">{{ $t->messages_count }}</td>
          <td class="small">{{ Fmt::when($t->last_member_at, '—') }} @if($isLate)<span class="tag bad">late</span>@endif</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>
{{ $tickets->links() }}
@endsection
