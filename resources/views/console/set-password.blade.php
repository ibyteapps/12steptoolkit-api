@extends('console.layout', ['title' => 'Set your password'])
@section('content')
<div class="panel" style="max-width:420px;margin:48px auto">
  <h1>Set your password</h1>
  <p class="muted">This link works once, and only for a little while.</p>
  <form method="post" action="{{ route('console.set-password.store', $token) }}">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email', $email) }}">
    <label for="password">New password</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
    <label for="password_confirmation">New password again</label>
    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
    @error('email')<p class="error">{{ $message }}</p>@enderror
    @error('password')<p class="error">{{ $message }}</p>@enderror
    <p class="muted" style="font-size:14px;margin-top:12px">Twelve characters or more. A few words you will remember beats a short one you will not.</p>
    <p style="margin-top:16px"><button type="submit">Set it and sign in</button></p>
  </form>
</div>
@endsection
