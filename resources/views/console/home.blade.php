@extends('console.layout', ['title' => 'Overview'])
@section('content')
@php use App\Support\Fmt; @endphp

@if($health['scheduler'] === null)
  <p class="note"><strong>The scheduler has not run in the last hour.</strong> That means the queue worker is not running either, so webhooks are arriving and never being processed. Add the cron entry: <code>* * * * * cd {{ base_path() }} && php artisan schedule:run</code></p>
@elseif($health['scheduler']->lt(now()->subMinutes(5)))
  <p class="note"><strong>The scheduler last ran {{ $health['scheduler']->diffForHumans() }}.</strong> It should run every minute.</p>
@endif

@if($health['failed_jobs'] > 0)
  <p class="note">{{ Fmt::count($health['failed_jobs']) }} failed {{ \Illuminate\Support\Str::plural('job', $health['failed_jobs']) }}. <code>php artisan queue:failed</code> says what they were.</p>
@endif

<div class="panel">
  <div class="spread">
    <h1>Waiting for someone</h1>
    <a href="{{ route('console.support') }}">Support →</a>
  </div>
  <div class="grid" style="margin-top:12px">
    <div><div class="muted small">Not yet answered</div><div class="num">{{ Fmt::count($waiting['open']) }}</div></div>
    <div><div class="muted small">Waiting on them</div><div class="num">{{ Fmt::count($waiting['answered']) }}</div></div>
    <div>
      <div class="muted small">Longer than {{ $waiting['sla'] }} hours</div>
      <div class="num" @if($waiting['late'] > 0) style="color:var(--bad)" @endif>{{ Fmt::count($waiting['late']) }}</div>
    </div>
  </div>
</div>

@if($wrong !== [])
<div class="panel">
  <h2>Worth a look</h2>
  <p class="muted small">Nobody reports these, because the person affected does not know what to call it.</p>
  <table>
    <tbody>
    @foreach($wrong as $w)
      <tr>
        <td class="n" style="width:1%"><strong>{{ Fmt::count($w['count']) }}</strong></td>
        <td><a href="{{ $w['href'] }}">{{ $w['label'] }}</a><div class="muted small">{{ $w['why'] }}</div></td>
      </tr>
    @endforeach
    </tbody>
  </table>
</div>
@endif

<div class="panel">
  <div class="spread">
    <h2>The week</h2>
    <a href="{{ route('console.cutover') }}">Cutover →</a>
  </div>
  <div class="grid">
    <div><div class="muted small">Accounts</div><div class="num">{{ Fmt::count($numbers['accounts']) }}</div></div>
    <div><div class="muted small">With premium</div><div class="num">{{ Fmt::count($numbers['premium']) }}</div></div>
    <div><div class="muted small">Joined this week</div><div class="num">{{ Fmt::count($numbers['joined_this_week']) }}</div></div>
    <div><div class="muted small">Sign-ins this week</div><div class="num">{{ Fmt::count($numbers['sign_ins_this_week']) }}</div></div>
    <div><div class="muted small">On 2.0</div><div class="num">{{ Fmt::count($numbers['sealed']) }}</div><div class="muted small">of {{ Fmt::count($numbers['accounts']) }}</div></div>
  </div>
</div>

<div class="panel">
  <h3>What this console will not show you</h3>
  <p class="muted small" style="margin-bottom:0">
    No inventory, no amend, no journal entry, no nightly review and no note — not to anybody, at any permission level. What you get is how many there are and when they were last written, which is what answers “is my backup working”. If you ever need more than that to help somebody, say so rather than going round it.
  </p>
</div>
@endsection
