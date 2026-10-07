<!doctype html>
<html lang="en" data-theme="{{ $theme ?? 'light' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title ?? 'Console' }} · 12 Step Toolkit</title>
{{-- No build step. The console is a tool, not a product, and there should be
     nothing between somebody and a support ticket. One stylesheet, inline, that
     a deployment cannot fail to compile. --}}
<style>
:root{--bg:#f4f6fa;--panel:#fff;--line:#dde3ec;--text:#15181f;--muted:#596172;--brand:#3b4a8f;--accent:#ffb300;--good:#1d7a4c;--bad:#be2b22;--warn:#8a5a00;--radius:12px}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--bg:#0b0e13;--panel:#171c25;--line:#2a313d;--text:#e8ecf3;--muted:#a3acbb;--brand:#a3b0f2;--good:#54c98d;--bad:#ff8b80;--warn:#ffc65c}}
:root[data-theme="dark"]{--bg:#0b0e13;--panel:#171c25;--line:#2a313d;--text:#e8ecf3;--muted:#a3acbb;--brand:#a3b0f2;--good:#54c98d;--bad:#ff8b80;--warn:#ffc65c}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:var(--brand)}
header{background:var(--panel);border-bottom:1px solid var(--line);padding:10px 16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;position:sticky;top:0;z-index:5}
header nav{display:flex;gap:14px;flex-wrap:wrap}
header nav a{text-decoration:none;padding:4px 2px;border-bottom:2px solid transparent}
header nav a[aria-current="page"]{border-bottom-color:var(--brand);font-weight:600}
main{max-width:1150px;margin:0 auto;padding:16px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 4px} h2{font-size:17px;margin:0 0 12px} h3{font-size:15px;margin:0 0 8px}
.muted{color:var(--muted)} .small{font-size:14px}
.num{font-size:28px;font-weight:600;line-height:1.1}
.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
.row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.spread{display:flex;gap:12px;align-items:baseline;justify-content:space-between;flex-wrap:wrap}
label{display:block;margin:12px 0 4px;font-size:14px}
input,select,textarea{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--text);font:inherit}
textarea{min-height:120px;resize:vertical}
button{background:var(--brand);color:#fff;border:0;border-radius:8px;padding:9px 15px;font:inherit;cursor:pointer}
button.quiet{background:transparent;color:var(--brand);border:1px solid var(--line)}
button.danger{background:transparent;color:var(--bad);border:1px solid var(--line)}
table{width:100%;border-collapse:collapse;font-size:15px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
tbody tr:last-child td{border-bottom:0}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
.tag{display:inline-block;font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid var(--line);color:var(--muted);white-space:nowrap}
.tag.good{color:var(--good);border-color:currentColor} .tag.bad{color:var(--bad);border-color:currentColor} .tag.warn{color:var(--warn);border-color:currentColor}
.error{color:var(--bad);font-size:14px;margin-top:8px}
.done{border-left:3px solid var(--good);padding:10px 14px;background:var(--panel);border-radius:8px;margin-bottom:16px}
.note{border-left:3px solid var(--accent);padding:10px 14px;background:var(--panel);border-radius:8px;margin-bottom:16px;font-size:14px}
.bar{height:8px;border-radius:999px;background:var(--line);overflow:hidden}
.bar span{display:block;height:100%;background:var(--brand)}
.pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.pills a{text-decoration:none;font-size:14px;padding:5px 12px;border:1px solid var(--line);border-radius:999px;color:var(--text)}
.pills a[aria-current="page"]{background:var(--brand);color:#fff;border-color:var(--brand)}
.msg{border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px}
.msg.staff{border-left:3px solid var(--brand)}
.msg pre{margin:6px 0 0;white-space:pre-wrap;font:inherit}
nav[role="navigation"] svg{display:none}
</style>
</head>
<body>
@auth('console')
<header>
  <strong>12 Step Toolkit</strong>
  <nav>
    <a href="{{ route('console.home') }}" @if(request()->routeIs('console.home')) aria-current="page" @endif>Overview</a>
    <a href="{{ route('console.accounts') }}" @if(request()->routeIs('console.accounts*')) aria-current="page" @endif>Accounts</a>
    <a href="{{ route('console.subscriptions') }}" @if(request()->routeIs('console.subscriptions*')) aria-current="page" @endif>Subscriptions</a>
    <a href="{{ route('console.support') }}" @if(request()->routeIs('console.support*')) aria-current="page" @endif>Support</a>
    <a href="{{ route('console.cutover') }}" @if(request()->routeIs('console.cutover')) aria-current="page" @endif>Cutover</a>
    <a href="{{ route('console.audit') }}" @if(request()->routeIs('console.audit')) aria-current="page" @endif>Audit</a>
  </nav>
  <form method="post" action="{{ route('console.logout') }}" style="margin-left:auto">
    @csrf
    <span class="muted small" style="margin-right:10px">{{ auth('console')->user()->name }}</span>
    <button class="quiet">Sign out</button>
  </form>
</header>
@endauth
<main>
  @if(session('done'))<p class="done">{{ session('done') }}</p>@endif
  @yield('content')
</main>
</body>
</html>
