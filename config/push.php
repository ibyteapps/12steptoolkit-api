<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Push
    |---------------------------------------------------------------------------
    |
    | `log` writes what would have been sent and sends nothing, which is the
    | default everywhere until a Firebase service-account key is on the server.
    | `fcm` is the real transport.
    |
    | The reminder sender does not care which is in use: it advances the
    | schedule either way, so a deployment with no key still behaves like one
    | with a key that is failing, rather than firing a week of backlog the day
    | somebody configures it.
    |
    */

    'driver' => env('PUSH_DRIVER', 'log'),

    'fcm' => [
        // The Firebase service-account JSON. NOT the one in the old
        // `server/app/validate/` directory — that key is in git history and
        // wants rotating; see AUDIT_AND_IMPROVEMENTS.md §1.6.
        'credentials' => env('FCM_CREDENTIALS', ''),
        'project_id' => env('FCM_PROJECT_ID', ''),
        'timeout' => (int) env('FCM_TIMEOUT', 10),
    ],

];
