@extends('console.layout', ['title' => 'Sign in'])
@section('content')
<div class="panel" style="max-width:420px;margin:48px auto">
  <h1>Console</h1>
  <p class="muted">Staff only. Accounts are made with <code>artisan console:user</code>.</p>
  <form method="post" action="{{ route('console.login') }}">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    @error('email')<p class="error">{{ $message }}</p>@enderror
    <p style="margin-top:16px"><button type="submit">Sign in</button></p>
  </form>
</div>
@endsection
