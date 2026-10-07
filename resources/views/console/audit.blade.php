@extends('console.layout', ['title' => 'Audit'])
@section('content')
@php use App\Support\Fmt; @endphp

<div class="panel">
  <h1>Audit</h1>
  <p class="muted small">Who did what, including looking. A change leaves its own trace in the thing it changed; a look does not, so looks are recorded here. Everybody who can sign in can read this, including the records of their own actions.</p>
  <form method="get" class="row" style="margin-top:8px">
    <input name="action" value="{{ request('action') }}" placeholder="account.view, grant., ticket.reply…" style="flex:1;min-width:220px">
    <button>Filter</button>
    @if(request()->hasAny(['action', 'who']))<a href="{{ route('console.audit') }}">Clear</a>@endif
  </form>
</div>

<div class="panel">
  @if($entries->isEmpty())
    <p class="muted">Nothing recorded.</p>
  @else
    <table>
      <thead><tr><th>When</th><th>Who</th><th>Did</th><th>To</th><th>Details</th></tr></thead>
      <tbody>
      @foreach($entries as $e)
        <tr>
          <td class="small">{{ Fmt::exact($e->created_at) }}</td>
          <td class="small">{{ $e->consoleUser?->name ?? 'removed' }}</td>
          <td class="small"><code>{{ $e->action }}</code></td>
          <td class="small">
            @if($e->subject_type === 'Account')
              <a href="{{ route('console.accounts.show', $e->subject_id) }}">Account {{ $e->subject_id }}</a>
            @elseif($e->subject_type)
              {{ $e->subject_type }} {{ $e->subject_id }}
            @else
              <span class="muted">—</span>
            @endif
          </td>
          <td class="small muted">
            @foreach(($e->context ?? []) as $k => $v)<span class="tag">{{ $k }}: {{ is_scalar($v) ? $v : json_encode($v) }}</span> @endforeach
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>
{{ $entries->links() }}
@endsection
