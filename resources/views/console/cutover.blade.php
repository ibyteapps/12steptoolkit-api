@extends('console.layout', ['title' => 'Cutover'])
@section('content')
@php use App\Support\Fmt; @endphp

<div class="panel">
  <h1>Cutover</h1>
  <p class="muted small">The three figures <code>docs/CUTOVER.md</code> step 4 says to watch, plus the switches, so that deciding whether to roll back is not done from a log file at eleven at night.</p>
</div>

<div class="panel">
  <div class="spread"><h2>How far through</h2><span class="muted small">an account is sealed the first time it signs in on 2.0</span></div>
  <div class="grid">
    <div><div class="muted small">Accounts</div><div class="num">{{ Fmt::count($sealing['accounts']) }}</div></div>
    <div><div class="muted small">On 2.0</div><div class="num">{{ Fmt::count($sealing['sealed']) }}</div></div>
    <div><div class="muted small">Still on the old apps</div><div class="num">{{ Fmt::count($sealing['remaining']) }}</div></div>
    <div><div class="muted small">This week</div><div class="num">{{ Fmt::count($sealing['this_week']) }}</div></div>
  </div>
  <div class="bar" style="margin-top:14px"><span style="width:{{ $sealing['percent'] }}%"></span></div>
  <p class="muted small" style="margin:8px 0 0">
    {{ $sealing['percent'] }}% sealed.
    @if($sealing['weeks_left'] !== null)
      At this week's rate, everybody is through in about {{ $sealing['weeks_left'] }} {{ \Illuminate\Support\Str::plural('week', $sealing['weeks_left']) }}.
    @else
      Nobody was sealed this week, so there is no rate to project from yet.
    @endif
    Once this is close to the account count, the legacy paths can be switched off.
  </p>
</div>

<div class="panel">
  <div class="spread"><h2>Is everybody's writing still arriving?</h2><span class="muted small">a drop of more than a tenth is a rollback</span></div>
  <table>
    <thead>
      <tr><th>Day</th><th class="n">Records written</th><th class="n">Change</th>
      @foreach($writes['collections'] as $c)<th class="n small">{{ \Illuminate\Support\Str::limit(ucfirst($c), 8, '') }}</th>@endforeach
      </tr>
    </thead>
    <tbody>
    @foreach($writes['days'] as $d)
      <tr>
        <td class="small">{{ $d['date'] }}</td>
        <td class="n">{{ Fmt::count($d['total']) }}</td>
        <td class="n">
          @if($d['change'] === null)<span class="muted">—</span>
          @elseif($d['change'] <= -10)<span class="tag bad">{{ $d['change'] }}%</span>
          @elseif($d['change'] < 0)<span class="muted">{{ $d['change'] }}%</span>
          @else<span class="tag good">+{{ $d['change'] }}%</span>
          @endif
        </td>
        @foreach($writes['collections'] as $c)<td class="n small muted">{{ $d['per_collection'][$c] ?? 0 }}</td>@endforeach
      </tr>
    @endforeach
    </tbody>
  </table>
  <p class="muted small" style="margin-bottom:0">A <code>COUNT(*)</code> grouped by day. Nothing anybody wrote is read to produce this.</p>
</div>

<div class="panel">
  <div class="spread"><h2>Replay protection</h2><span class="muted small">the thing the live server has in a file and never wired up</span></div>
  <div class="grid">
    <div><div class="muted small">Nonces held</div><div class="num">{{ Fmt::count($replay['nonces_held']) }}</div></div>
    <div><div class="muted small">Signature window</div><div class="num">{{ $replay['window_seconds'] }}s</div></div>
    <div><div class="muted small">Kept for</div><div class="num">{{ round($replay['retention_seconds'] / 60) }}m</div></div>
  </div>
  @if($replay['nonces_held'] === 0)
    <p class="note" style="margin-top:14px">No nonces held. If signed requests are arriving, this is not working and every one of them is replayable — which is the live server's behaviour, not this one's. Worth checking before anything else.</p>
  @endif
</div>

<div class="panel">
  <div class="spread"><h2>Which apps are out there</h2><span class="muted small">{{ Fmt::count($installs['seen_30_days']) }} installs seen in 30 days of {{ Fmt::count($installs['total_known']) }} known</span></div>
  @if($installs['rows']->isEmpty())
    <p class="muted">No install has reported in. Until 2.0 ships that is expected: the old apps do not register an install at all.</p>
  @else
    <table>
      <thead><tr><th>Platform</th><th>App version</th><th class="n">Installs</th></tr></thead>
      <tbody>
      @foreach($installs['rows'] as $r)
        <tr><td>{{ Fmt::label($r->platform) }}</td><td class="small">{{ $r->app_version ?? 'not reported' }}</td><td class="n">{{ Fmt::count($r->c) }}</td></tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>

<div class="panel">
  <h2>Sign-ins this week</h2>
  @if($signIns['week'] === [])
    <p class="muted">None through this server yet.</p>
  @else
    <table>
      <thead><tr><th>How</th><th class="n">Sign-ins</th></tr></thead>
      <tbody>@foreach($signIns['week'] as $method => $n)<tr><td>{{ Fmt::label($method) }}</td><td class="n">{{ Fmt::count($n) }}</td></tr>@endforeach</tbody>
    </table>
  @endif
  <p class="muted small" style="margin-bottom:0">{{ Fmt::count($signIns['today']) }} today.</p>
</div>

<div class="panel">
  <h2>Where premium is coming from</h2>
  @if($entitlementSources === [])
    <p class="muted">Nothing granted yet.</p>
  @else
    <table>
      <thead><tr><th>Source</th><th class="n">Accounts</th></tr></thead>
      <tbody>@foreach($entitlementSources as $source => $n)<tr><td>{{ Fmt::label($source) }}</td><td class="n">{{ Fmt::count($n) }}</td></tr>@endforeach</tbody>
    </table>
    <p class="muted small" style="margin-bottom:0">When <code>revenuecat</code> reaches zero, the read-only bridge can be switched off.</p>
  @endif
</div>

<div class="panel">
  <h2>The switches</h2>
  <table>
    <tbody>
      <tr><td>Android (v19) paths</td><td>@if($switches['v19'])<span class="tag good">answering</span>@else<span class="tag">off</span>@endif</td>
        <td class="muted small">Every shipped Android 1.9.0 needs these.</td></tr>
      <tr><td>Apple (v8) paths</td><td>@if($switches['v8'])<span class="tag bad">on</span>@else<span class="tag">off</span>@endif</td>
        <td class="muted small">@if($switches['v8'])<strong>On, and the endpoints answer 503.</strong> The Apple layer is not built — see <code>IMPLEMENTATION_STATUS.md</code>.@else Correct: the Apple layer is not built yet.@endif</td></tr>
      <tr><td>Sealing</td><td>@if($switches['sealing'])<span class="tag good">on</span>@else<span class="tag bad">off</span>@endif</td>
        <td class="muted small">@if($switches['sealing'])The legacy exposure shrinks with every 2.0 sign-in.@else Accounts keep their weaker door open after upgrading.@endif</td></tr>
      <tr><td>Plaintext password column</td><td><span class="tag {{ $switches['plaintext_password'] === 'keep' ? 'warn' : 'good' }}">{{ $switches['plaintext_password'] }}</span></td>
        <td class="muted small">@if($switches['plaintext_password'] === 'keep')Both apps work. Switch to <code>clear</code> after the cutover.@else iOS email sign-in is ending as people sign in.@endif</td></tr>
      <tr><td>RevenueCat bridge</td><td>@if($switches['revenuecat_bridge'])<span class="tag good">reading</span>@else<span class="tag">off</span>@endif</td>
        <td class="muted small">Read-only. Nothing here ever writes to RevenueCat.</td></tr>
      <tr><td>Backup needs a subscription</td><td>@if($switches['sync_needs_subscription'])<span class="tag bad">yes</span>@else<span class="tag good">no</span>@endif</td>
        <td class="muted small">D-001: backup is free for everyone. The old Android cleared the dirty flags for non-subscribers, so a free user's writing was never uploaded at all.</td></tr>
    </tbody>
  </table>
</div>
@endsection
