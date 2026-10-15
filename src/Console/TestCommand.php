<?php

namespace Nacew\Utestme\Console;

use Illuminate\Console\Command;
use Nacew\Utestme\Client;
use RuntimeException;

/** `php artisan utestme:test` — send one test error, and say whether uTestMe took it. */
class TestCommand extends Command
{
    protected $signature = 'utestme:test';

    protected $description = 'Send a test error to uTestMe';

    public function handle(Client $client): int
    {
        if (! $client->enabled()) {
            $this->error('No key: set UTESTME_KEY (utm_sk_…) in .env, then run php artisan config:clear.');

            return self::FAILURE;
        }
        $client->addBreadcrumb('[utestme:test] sending a test error');
        $client->capture(new RuntimeException('uTestMe test error: the Laravel SDK is connected'), [
            'level' => 'warn',
            'handled' => true,
        ]);
        $status = $client->flush();
        if ($status !== null && $status >= 200 && $status < 300) {
            $this->info('Sent. It shows in uTestMe → Logs in a few seconds.');

            return self::SUCCESS;
        }
        $this->error($status === 401
            ? 'uTestMe refused the key. Make a new one on the app\'s page and put it in UTESTME_KEY.'
            : 'Could not reach uTestMe ('.($status ?? 'no answer').'). Check UTESTME_URL and that the server can make outgoing HTTPS requests.');

        return self::FAILURE;
    }
}
