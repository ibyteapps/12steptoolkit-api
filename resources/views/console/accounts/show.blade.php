@extends('console.layout', ['title' => 'Account '.$account['id']])
@section('content')
@php use App\Support\Fmt; @endphp

<div class="panel">
  <div class="spread">
    <div>
      <h1>{{ $account['nickname'] }} <span class="muted">· {{ $account['id'] }}</span></h1>
      <p class="muted small" style="margin:0">{{ $account['email'] }} · {{ $account['sign_in'] }} · {{ $account['platform'] }}</p>
    </div>
    <div class="row">
      @if($entitlement['is_active'])
        <span class="tag {{ Fmt::stateClass($entitlement['state']) }}">Premium — {{ Fmt::label($entitlement['state']) }}</span>
      @else
        <span class="tag">No premium</span>
      @endif
      @if($account['sealed'])<span class="tag good">On 2.0</span>@endif
      @if($account['deletion_requested'])<span class="tag bad">Deletion asked for</span>@endif
    </div>
  </div>
</div>

@if($account['deletion_requested'])
  <p class="note"><strong>This person asked to be deleted {{ $account['deletion_requested']->diffForHumans() }}</strong> ({{ Fmt::exact($account['deletion_requested']) }}). If their data is still here, the erase has not run.</p>
@endif

{{-- The question support is actually asked, answered first. --}}
<div class="panel">
  <div class="spread">
    <h2>Is their backup working?</h2>
    <span class="muted small">{{ Fmt::count($totals['records']) }} records · last written {{ Fmt::when($totals['last'], 'never') }}</span>
  </div>
  <table>
    <thead><tr><th>Collection</th><th class="n">How many</th><th>Last written</th></tr></thead>
    <tbody>
    @foreach($work as $w)
      <tr>
        <td>{{ $w['label'] }}</td>
        <td class="n">{{ Fmt::count($w['count']) }}</td>
        <td class="small">{{ Fmt::when($w['last'], '—') }}<span class="muted"> {{ $w['last'] ? '· '.Fmt::exact($w['last']) : '' }}</span></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <p class="muted small" style="margin-bottom:0">Counts and dates only. Nothing on this page can show what any of it says.</p>
</div>

<div class="panel">
  <h2>Premium</h2>
  <div class="grid">
    <div><div class="muted small">State</div><div><span class="tag {{ Fmt::stateClass($entitlement['state']) }}">{{ Fmt::label($entitlement['state']) }}</span></div></div>
    <div><div class="muted small">Granted by</div><div>{{ Fmt::label($entitlement['source'] ?? null) }}</div></div>
    <div><div class="muted small">Runs until</div><div class="small">{{ Fmt::exact($entitlement['expires_at'] ?? null, 'no end date') }}</div></div>
    <div><div class="muted small">Renews</div><div>{{ ($entitlement['will_renew'] ?? false) ? 'yes' : 'no' }}</div></div>
    <div><div class="muted small">Last recomputed</div><div class="small">{{ Fmt::when($entitlement['synced_at'] ?? null) }}</div></div>
  </div>

  @if(($entitlement['grace_period_expires_at'] ?? null))
    <p class="note" style="margin-top:14px">Their card is being retried. They keep access until {{ Fmt::exact($entitlement['grace_period_expires_at']) }}.</p>
  @endif

  @if($account['ever_paid_flag'] && ! $entitlement['is_active'])
    <p class="note" style="margin-top:14px">The old <code>subscribed</code> flag is set, which means they have paid at some point — it was never cleared by the old apps, so it is not evidence of premium now. The old apps may still be letting them in on the strength of it.</p>
  @endif
</div>

<div class="panel">
  <div class="spread"><h2>Purchases</h2><span class="muted small">{{ $subscriptions->count() }}</span></div>
  @if($subscriptions->isEmpty())
    <p class="muted">Nothing this server has verified. Anything bought before this server existed shows up through RevenueCat@if(($entitlement['revenuecat_active'] ?? null) === true), which is what is granting their premium right now@endif.</p>
  @else
    <table>
      <thead><tr><th>Store</th><th>Product</th><th>State</th><th>Bought</th><th>Access until</th><th class="n">Paid</th><th></th></tr></thead>
      <tbody>
      @foreach($subscriptions as $s)
        <tr>
          <td>{{ ucfirst($s['store']) }} @if($s['sandbox'])<span class="tag warn">sandbox</span>@endif</td>
          <td class="small">{{ $s['product_id'] }}
            @if($s['unmapped'])<div><span class="tag bad">unknown product — grants nothing here</span></div>@endif
          </td>
          <td><span class="tag {{ Fmt::stateClass($s['status']) }}">{{ Fmt::label($s['status']) }}</span></td>
          <td class="small">{{ Fmt::when($s['purchased_at']) }}</td>
          <td class="small">{{ Fmt::exact($s['access_until'], 'no end date') }}</td>
          <td class="n">{{ Fmt::gbp((int) $s['paid']) }}</td>
          <td>
            <form method="post" action="{{ route('console.subscriptions.refresh', $s['id']) }}">@csrf<button class="quiet">Re-check</button></form>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>

<div class="panel">
  <h2>Given subscriptions</h2>
  @if($grants->isNotEmpty())
    <table>
      <thead><tr><th>For</th><th>Why</th><th>By</th><th>Runs</th><th></th></tr></thead>
      <tbody>
      @foreach($grants as $g)
        <tr>
          <td>{{ $g['period'] }} @if($g['active'])<span class="tag good">live</span>@elseif($g['revoked_at'])<span class="tag bad">revoked</span>@endif</td>
          <td class="small">{{ $g['reason'] }}</td>
          <td class="small">{{ $g['by'] }}</td>
          <td class="small">{{ $g['starts_at']->toDateString() }} → {{ $g['ends_at']?->toDateString() ?? 'no end' }}</td>
          <td>
            @if($g['active'])
            <form method="post" action="{{ route('console.grants.destroy', [$account['id'], $g['id']]) }}">
              @csrf @method('DELETE')
              <button class="danger">Revoke</button>
            </form>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  <form method="post" action="{{ route('console.grants.store', $account['id']) }}" style="margin-top:12px">
    @csrf
    <div class="row" style="align-items:flex-end">
      <div style="min-width:150px">
        <label for="period">Give premium for</label>
        <select id="period" name="period">
          @foreach(\App\Models\ComplimentaryGrant::PERIODS as $key => $label)
            <option value="{{ $key }}">{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div style="flex:1;min-width:240px">
        <label for="reason">Why — this is kept, and shown here for good</label>
        <input id="reason" name="reason" value="{{ old('reason') }}" placeholder="Refund went wrong, ticket #214" required>
      </div>
      <div><button>Give it</button></div>
    </div>
    @error('reason')<p class="error">{{ $message }}</p>@enderror
    @error('period')<p class="error">{{ $message }}</p>@enderror
  </form>
  <p class="muted small" style="margin-bottom:0">It stacks with whatever the stores say, and nothing can take away access that something else still grants.</p>
</div>

<div class="panel">
  <h2>Their phones</h2>
  @if($installs->isEmpty())
    <p class="muted">No install has reported in to this server. On 1.9.0 or 1.6.6 that is expected — the old scripts recorded one push token per account and nothing else.</p>
  @else
    <table>
      <thead><tr><th>Platform</th><th>App</th><th>OS</th><th>Last seen</th><th>Notifications</th></tr></thead>
      <tbody>
      @foreach($installs as $i)
        <tr>
          <td>{{ Fmt::label($i['platform']) }}</td>
          <td class="small">{{ $i['app_version'] ?? '—' }}</td>
          <td class="small">{{ $i['os_version'] ?? '—' }}</td>
          <td class="small">{{ Fmt::when($i['last_seen_at']) }}</td>
          <td>{{ $i['push'] ? 'on' : 'off' }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>

<div class="panel">
  <h2>The account itself</h2>
  <div class="grid">
    <div><div class="muted small">Joined</div><div class="small">{{ Fmt::exact($account['created']) }}</div></div>
    <div><div class="muted small">Last signed in</div><div class="small">{{ Fmt::exact($account['last_seen'], 'never') }}</div></div>
    <div><div class="muted small">Times signed in</div><div>{{ Fmt::count($account['sign_in_count']) }}</div></div>
    <div><div class="muted small">Sobriety date</div><div>{{ $account['sobriety_date'] ?? 'not set' }}</div></div>
    <div><div class="muted small">Email verified</div><div>{{ $account['verified'] ? 'yes' : 'no' }}</div></div>
    <div><div class="muted small">Country</div><div>{{ $account['country'] ?? '—' }}</div></div>
    <div><div class="muted small">Time zone</div><div class="small">{{ $account['timezone'] ?? '—' }}</div></div>
    <div><div class="muted small">On 2.0 since</div><div class="small">{{ Fmt::exact($account['sealed_at'], 'not yet') }}</div></div>
    <div><div class="muted small">Identifier</div><div class="small" style="word-break:break-all">{{ $account['uuid'] }}</div></div>
  </div>
  @if($account['sealed'])
    <p class="muted small" style="margin-bottom:0">Sealed: the old Apple shared-secret path no longer answers for this account.</p>
  @endif
</div>

@if($tickets->isNotEmpty())
<div class="panel">
  <h2>Their tickets</h2>
  <table>
    <thead><tr><th>Subject</th><th>State</th><th>Last heard from them</th></tr></thead>
    <tbody>
    @foreach($tickets as $t)
      <tr>
        <td><a href="{{ route('console.support.show', $t->uuid) }}">{{ $t->subject }}</a></td>
        <td><span class="tag">{{ $t->state }}</span></td>
        <td class="small">{{ Fmt::when($t->last_member_at) }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
</div>
@endif
@endsection
