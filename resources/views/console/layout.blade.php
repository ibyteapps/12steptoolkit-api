<!doctype html>
<html lang="en" data-theme="{{ $theme ?? 'light' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title ?? 'Console' }} · 12 Step Toolkit</title>
{{-- No build step. The console is a tool, not a product, and there should be
     nothing between somebody and a support ticket. --}}
<style>
:root{--bg:#f4f6fa;--panel:#fff;--line:#dde3ec;--text:#15181f;--muted:#596172;--brand:#3b4a8f;--accent:#ffb300;--radius:12px}
:root[data-theme="dark"]{--bg:#0b0e13;--panel:#171c25;--line:#2a313d;--text:#e8ecf3;--muted:#a3acbb;--brand:#a3b0f2}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
header{background:var(--panel);border-bottom:1px solid var(--line);padding:12px 16px;display:flex;gap:16px;align-items:center}
main{max-width:1100px;margin:0 auto;padding:16px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 4px} h2{font-size:17px;margin:0 0 12px}
.muted{color:var(--muted)} .num{font-size:28px;font-weight:600}
.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
label{display:block;margin:12px 0 4px;font-size:14px}
input{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--text)}
button{background:var(--brand);color:#fff;border:0;border-radius:8px;padding:10px 16px;font:inherit;cursor:pointer}
.error{color:#be2b22;font-size:14px;margin-top:8px}
</style>
</head>
<body>
@auth('console')
<header>
  <strong>12 Step Toolkit</strong>
  <a href="{{ route('console.home') }}">Overview</a>
  <form method="post" action="{{ route('console.logout') }}" style="margin-left:auto">@csrf<button>Sign out</button></form>
</header>
@endauth
<main>@yield('content')</main>
</body>
</html>
