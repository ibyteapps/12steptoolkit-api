@extends('console.layout', ['title' => 'Overview'])
@section('content')
<h1>Overview</h1>
<p class="muted">Counts and dates only. Nothing anybody wrote is readable from here.</p>

<div class="panel">
  <h2>What is waiting</h2>
  <div class="grid">
    <div><div class="num">{{ $waiting['open_tickets'] }}</div><div class="muted">tickets waiting on us</div></div>
    <div><div class="num">{{ $waiting['answered_tickets'] }}</div><div class="muted">answered, waiting on them</div></div>
  </div>
</div>

<div class="panel">
  <h2>The numbers</h2>
  <div class="grid">
    <div><div class="num">{{ number_format($numbers['accounts']) }}</div><div class="muted">accounts</div></div>
    <div><div class="num">{{ number_format($numbers['active_subscriptions']) }}</div><div class="muted">active subscriptions</div></div>
  </div>
</div>
@endsection
