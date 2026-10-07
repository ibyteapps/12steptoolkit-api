@extends('console.layout', ['title' => 'Subscriptions'])
@section('content')
@php use App\Support\Fmt; @endphp

<div class="panel">
  <h1>Subscriptions</h1>
  <div class="grid" style="margin-top:12px">
    <div><div class="muted small">Granting access</div><div class="num">{{ Fmt::count($counts['granting']) }}</div></div>
    <div><div class="muted small">Premium accounts</div><div class="num">{{ Fmt::count($counts['entitled']) }}</div></div>
    <div><div class="muted small">No account attached</div><div class="num">{{ Fmt::count($counts['unattached']) }}</div><div class="muted small">money taken, nothing given</div></div>
    <div><div class="muted small">Refunded</div><div class="num">{{ Fmt::count($counts['refunded']) }}</div></div>
  </div>
</div>

<div class="panel">
  <div class="spread"><h2>Where premium is coming from</h2><span class="muted small">active entitlements</span></div>
  @if($counts['by_source'] === [])
    <p class="muted">Nothing granted yet.</p>
  @else
    <table>
      <thead><tr><th>Source</th><th class="n">Accounts</th><th></th></tr></thead>
      <tbody>
      @php $max = max($counts['by_source']); @endphp
      @foreach($counts['by_source'] as $source => $n)
        <tr>
          <td>{{ Fmt::label($source) }}</td>
          <td class="n">{{ Fmt::count($n) }}</td>
          <td style="width:45%"><div class="bar"><span style="width:{{ $max > 0 ? round($n / $max * 100) : 0 }}%"></span></div></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    <p class="muted small" style="margin-bottom:0">During the overlap most of this is <code>revenuecat</code>. Watching that fall is how the bridge's switch-off date gets decided rather than guessed.</p>
  @endif
</div>

<div class="panel">
  <div class="spread"><h2>Takings, last 30 days</h2>
    <span class="muted small">{{ $money['recorded_from'] ? 'recorded since '.$money['recorded_from']->toDateString() : 'nothing recorded yet' }}</span>
  </div>
  @if($money['thirty_days_orders'] === 0)
    <p class="muted">No orders recorded. Every subscription sold so far went through RevenueCat, and the bridge is read-only — it records entitlements, not payments. Figures appear here once the store webhooks are live.</p>
  @else
    <div class="grid">
      <div><div class="muted small">Gross</div><div class="num">{{ Fmt::gbp($money['thirty_days_gbp_milli']) }}</div></div>
      <div><div class="muted small">After the store's share</div><div class="num">{{ Fmt::gbp($money['thirty_days_net_gbp_milli']) }}</div></div>
      <div><div class="muted small">Payments</div><div class="num">{{ Fmt::count($money['thirty_days_orders']) }}</div></div>
      <div><div class="muted small">Refunded, all time</div><div class="num">{{ Fmt::gbp($money['refunded_gbp_milli']) }}</div></div>
    </div>
  @endif
</div>

<div class="pills">
  @foreach($views as $key => $label)
    <a href="{{ route('console.subscriptions', ['view' => $key, 'store' => $store ?: null]) }}" @if($view === $key) aria-current="page" @endif>{{ $label }}</a>
  @endforeach
</div>
<div class="pills">
  <a href="{{ route('console.subscriptions', ['view' => $view]) }}" @if($store === '') aria-current="page" @endif>Both stores</a>
  <a href="{{ route('console.subscriptions', ['view' => $view, 'store' => 'apple']) }}" @if($store === 'apple') aria-current="page" @endif>Apple</a>
  <a href="{{ route('console.subscriptions', ['view' => $view, 'store' => 'google']) }}" @if($store === 'google') aria-current="page" @endif>Google</a>
</div>

@if($view === 'unattached')
  <p class="note">A purchase with no account. Both stores let somebody buy before they sign in, and the old system recorded nothing at all — the money was taken and no row existed. These are the people who write in saying “I paid and I have nothing”.</p>
@elseif($view === 'unmapped')
  <p class="note">A product id this server does not recognise, so it grants nothing from here. The Google ids are <strong>not confirmed</strong> — they are RevenueCat package names in the app, not Play product ids. See <code>docs/OPEN_QUESTIONS.md</code> C1. Nobody is locked out meanwhile: their access comes through the RevenueCat bridge.</p>
@endif

<div class="panel">
  @if($subscriptions->isEmpty())
    <p class="muted">Nothing here.</p>
  @else
    <table>
      <thead><tr><th>Who</th><th>Store</th><th>Product</th><th>State</th><th>Bought</th><th>Access until</th><th></th></tr></thead>
      <tbody>
      @foreach($subscriptions as $s)
        <tr>
          <td>
            @if($s->account_id)
              <a href="{{ route('console.accounts.show', $s->account_id) }}">{{ $s->account_id }}</a>
              <div class="muted small">{{ trim((string) ($s->account->nickname ?? '')) ?: ($s->account->email ?? '') }}</div>
            @else
              <span class="tag bad">nobody</span>
            @endif
          </td>
          <td>{{ ucfirst($s->store) }}@if($s->is_sandbox) <span class="tag warn">sandbox</span>@endif</td>
          <td class="small">{{ $s->product_id }}@if($s->isUnmapped())<div><span class="tag bad">unknown</span></div>@endif</td>
          <td><span class="tag {{ Fmt::stateClass($s->status) }}">{{ Fmt::label($s->status) }}</span>@if(! $s->will_renew && $s->status === 'active')<div class="muted small">will not renew</div>@endif</td>
          <td class="small">{{ Fmt::when($s->purchased_at) }}</td>
          <td class="small">{{ Fmt::exact($s->accessUntil(), 'no end date') }}</td>
          <td>@if($s->account_id)<form method="post" action="{{ route('console.subscriptions.refresh', $s) }}">@csrf<button class="quiet">Re-check</button></form>@endif</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>
{{ $subscriptions->links() }}
@endsection
