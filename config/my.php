<?php

return [

    /*
    |---------------------------------------------------------------------------
    | The member area (/my)
    |---------------------------------------------------------------------------
    |
    | The web version of the app, served from the same application as the API
    | it reads. It replaces web.12steptoolkit.com, whose endpoints authorised
    | nothing: they took an account id from the request body and trusted it.
    |
    | Here every query goes through `StepRecord::ownedBy(Auth::id())`, and the
    | id comes from a signed session cookie that the browser cannot author.
    |
    */

    'session_driver' => env('MY_SESSION_DRIVER', 'database'),

    // Fourteen days. Someone writing a nightly inventory should not be asked
    // to sign in again every evening.
    'session_minutes' => (int) env('MY_SESSION_MINUTES', 20160),

    // How long a sign-in code is good for, and how many tries it gets before
    // it is thrown away.
    'code_minutes' => (int) env('MY_CODE_MINUTES', 10),
    'code_attempts' => (int) env('MY_CODE_ATTEMPTS', 5),

    // Rows per page in the record lists.
    'per_page' => (int) env('MY_PER_PAGE', 20),

];
