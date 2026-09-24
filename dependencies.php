<?php

require_once __DIR__ . '/vendor/autoload.php';

// httponly: no script reads PHPSESSID. use_strict_mode: PHP refuses a session id it did not
// create itself (session fixation). No `secure` flag here: behind plain HTTP or a TLS-terminating
// proxy it would drop the session cookie entirely.
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);
