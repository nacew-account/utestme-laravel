<?php

namespace Nacew\Utestme;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Nacew\Utestme\Console\TestCommand;
use Nacew\Utestme\Http\CountRequests;
use Throwable;

/**
 * Registered by Laravel's package discovery: installing the package and
 * setting UTESTME_KEY is all it takes.
 *
 * It adds itself to Laravel's exception reporting (so what Laravel would not
 * report — a 404, a validation error — is not sent either), counts requests
 * with a global middleware, and keeps the last log lines and SQL statements
 * as context. Nothing it does can change a response or stop the app's own
 * reporting.
 */
class UtestmeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/utestme.php', 'utestme');

        $this->app->singleton(Client::class, function ($app) {
            $config = $app['config']->get('utestme', []);
            $cache = null;
            try {
                $cache = $app['cache']->store($config['cache_store'] ?? null);
            } catch (Throwable) {
                $cache = null;
            }

            return new Client($config, $cache, $app->basePath(), $app->version());
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class]);
            $this->publishes([__DIR__.'/../config/utestme.php' => config_path('utestme.php')], 'utestme-config');
        }

        /** @var Client $client */
        $client = $this->app->make(Client::class);
        if (! $client->enabled()) {
            return;
        }

        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(function (Throwable $e) use ($client) {
                $client->capture($e);
                // A command or a queued job may run for hours: send now.
                if ($this->app->runningInConsole()) {
                    $client->flush();
                }
            });
        }

        if (! $this->app->runningInConsole() && $this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);
            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(CountRequests::class);
            }
        }

        // The route is known before the controller runs, so an error inside it has it.
        Event::listen(RouteMatched::class, function (RouteMatched $event) use ($client) {
            $client->setRoute($event->route->uri());
        });

        if ($this->app['config']->get('utestme.breadcrumbs', true)) {
            Event::listen(MessageLogged::class, function (MessageLogged $log) use ($client) {
                $client->addBreadcrumb("[log.{$log->level}] {$log->message}");
            });
            Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($client) {
                // The statement only: its values (bindings) never leave the app.
                $client->addBreadcrumb("[sql {$query->time}ms] {$query->sql}");
            });
        }

        $this->app->terminating(function () use ($client) {
            $client->flush();
        });
    }
}
