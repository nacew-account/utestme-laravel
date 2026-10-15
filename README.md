# nacew/utestme-laravel

Server errors for Laravel apps, sent to uTestMe: every exception Laravel reports, with its class, stack, file and line and the request it happened in, the log lines and SQL statements just before it (never their values), and requests and 5xx responses per minute. It works on shared hosting (Hostinger, cPanel) where the server's own access logs cannot be read.

It never sends request bodies, headers, cookies or query strings, and uTestMe removes secrets from what it does send. It sends after the response has gone to the visitor, so it never slows a page down, and it never changes a response or Laravel's own reporting.

## Install

```bash
composer require nacew/utestme-laravel
```

Get a key in uTestMe: **Logs → Add server or app → An app → Laravel**. Put it in `.env`:

```bash
UTESTME_KEY=utm_sk_…
UTESTME_URL=https://utest.me
UTESTME_APP=shop
```

Then:

```bash
php artisan config:clear
php artisan utestme:test   # sends one test error, and says whether uTestMe took it
```

That is all: the package registers itself through Laravel's package discovery.

## What it sends

- **Errors**: what Laravel reports (so a 404 or a validation error, which Laravel does not report, is not sent either). From a queued job or an artisan command, at once; from a web request, after the response.
- **Requests and 5xx per minute**: counted in Laravel's cache between requests, and sent by the first request of the next minute. It needs a cache that persists (`file`, `database`, `redis`; not `array`).
- **Context**: the last 10 log lines and SQL statements before each error. SQL is sent as written, with `?` for its values.

## Options

`php artisan vendor:publish --tag=utestme-config` copies `config/utestme.php`:

| Key              | `.env`                   | Default            |
| ---------------- | ------------------------ | ------------------ |
| `key`            | `UTESTME_KEY`            |                    |
| `url`            | `UTESTME_URL`            | `https://utest.me` |
| `app`            | `UTESTME_APP`            | `APP_NAME`         |
| `release`        | `UTESTME_RELEASE`        |                    |
| `environment`    | `UTESTME_ENVIRONMENT`    | `APP_ENV`          |
| `count_requests` | `UTESTME_COUNT_REQUESTS` | `true`             |
| `cache_store`    | `UTESTME_CACHE_STORE`    | the default store  |
| `breadcrumbs`    | `UTESTME_BREADCRUMBS`    | `true`             |

PHP 8.1+, Laravel 10 to 13.
