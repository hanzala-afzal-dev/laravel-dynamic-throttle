<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Sends requests to the demo routes through the app's HTTP kernel, from many IPs and
 * tenants, so the dashboard has blocks, would-blocks and offenders to show. They go
 * in-process rather than over HTTP, so each can come from its own IP.
 */
final class DemoTrafficCommand extends Command
{
    protected $signature = 'demo:traffic
        {--minutes=1 : How many minutes to keep sending}
        {--per-minute=150 : How many requests each minute}';

    protected $description = 'Send demo requests through the demo rules, for the dashboard';

    /** A few busy clients that hit the limits, and many quiet ones that do not. */
    private const array BUSY = ['203.0.113.21', '203.0.113.22', '203.0.113.23'];

    public function handle(Kernel $kernel): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $perMinute = max(1, (int) $this->option('per-minute'));
        $statuses = [];

        for ($minute = 1; $minute <= $minutes; $minute++) {
            $started = time();

            for ($sent = 0; $sent < $perMinute; $sent++) {
                [$path, $server] = $this->next();
                $request = Request::create($path, 'GET', server: $server);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
                $status = $response->getStatusCode();
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            }

            $this->line("Minute {$minute} of {$minutes}: sent {$perMinute} requests.");

            if ($minute < $minutes) {
                sleep(max(0, 60 - (time() - $started)));
            }
        }

        ksort($statuses);
        $this->info('Answers: '.implode(', ', array_map(fn (int $status, int $count): string => "{$count} × {$status}", array_keys($statuses), $statuses)).'.');
        $this->line('Open the dashboard\'s Overview: the last hour shows them at once.');

        return self::SUCCESS;
    }

    /**
     * The next request: its path, and the server variables that give its IP and tenant.
     *
     * @return array{string, array<string, string>}
     */
    private function next(): array
    {
        $ip = random_int(1, 100) <= 40 ? self::BUSY[random_int(0, count(self::BUSY) - 1)] : '198.51.100.'.random_int(1, 60);
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json'];
        $roll = random_int(1, 100);

        if ($roll <= 55) {
            return ['/api/orders', $server];
        }

        if ($roll <= 85) {
            return ['/api/search', $server];
        }

        return ['/api/export', [...$server, 'HTTP_X_TENANT' => 'tenant-'.random_int(1, 4)]];
    }
}
