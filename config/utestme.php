<?php

return [
    // The app's key (utm_sk_…), from uTestMe → Logs → Add server or app → An app.
    // Without one, nothing is sent.
    'key' => env('UTESTME_KEY'),

    'url' => env('UTESTME_URL', 'https://utest.me'),

    // The service's name, shown on each error.
    'app' => env('UTESTME_APP', env('APP_NAME', 'laravel')),

    // The version running (a git commit, a tag), to tie errors to a deploy.
    'release' => env('UTESTME_RELEASE'),

    'environment' => env('UTESTME_ENVIRONMENT', env('APP_ENV')),

    // Count requests and 5xx per minute. Kept in the cache between requests,
    // so it needs a cache that persists (file, database, redis — not "array").
    'count_requests' => env('UTESTME_COUNT_REQUESTS', true),
    'cache_store' => env('UTESTME_CACHE_STORE'),

    // Keep the last log lines and SQL statements (never their values) as each
    // error's context.
    'breadcrumbs' => env('UTESTME_BREADCRUMBS', true),

    // Seconds to wait for uTestMe. The send happens after the response has
    // gone to the visitor, so this never slows a page down.
    'timeout' => 3,
];
