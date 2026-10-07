<?php

/*
|--------------------------------------------------------------------------
| The console — /console, the staff back office
|--------------------------------------------------------------------------
| Accounts are created on the server (`php artisan console:user`); there is no
| registration page. Nothing here is a secret.
*/

return [
    // Where console sessions are kept: `database` (the sessions table) or `file`.
    'session_driver' => env('CONSOLE_SESSION_DRIVER', 'database'),
    'session_minutes' => (int) env('CONSOLE_SESSION_MINUTES', 720),

    // How long a "set your password" link works.
    'password_link_minutes' => 30,

    // A support ticket waiting longer than this is shown as late.
    'support_sla_hours' => (int) env('SUPPORT_SLA_HOURS', 24),
];
