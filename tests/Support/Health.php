<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * dynamic-throttle:health, as SPEC-016's tests run it and read its report.
 */
final class Health
{
    /** The check ids, in the order the report lists them (SPEC-016). */
    public const array CHECKS = [
        'config', 'store', 'cache-clear', 'app-key',
        'attached', 'except', 'two-limiters', 'middleware-order',
        'manifest', 'metrics',
        'guests', 'dominant-ip', 'ignored',
    ];

    /**
     * An app where every check passes: a `dynamodb` store, which the test keeps in
     * memory, a rule, a route that runs dynamic.throttle, unless $attached is false,
     * and the metrics flushed through the last complete minute. The clock must be
     * frozen.
     */
    public static function passing(bool $attached = true): void
    {
        self::inMemory('dynamodb');
        config()->set('cache.stores.health', ['driver' => 'dynamodb']);
        config()->set('dynamic-throttle.store', 'health');
        Throttling::rule();

        if ($attached) {
            Throttling::route('api/x');
        }

        Telemetry::buffer()->markFlushed(intdiv(Date::now()->getTimestamp(), 60) - 2);
    }

    /**
     * Makes stores of a cache driver keep their keys in memory, so a test can name the
     * driver without its service: the checks read the driver's name from the config.
     */
    public static function inMemory(string $driver): void
    {
        Cache::extend($driver, fn (): Repository => new Repository(new ArrayStore));
    }

    /**
     * The counter store answers every read with an error, as a cache that is down
     * does. The scoped services are built again on it.
     */
    public static function storeDown(): void
    {
        Cache::extend('down', fn (): Repository => new Repository(new class extends ArrayStore
        {
            public function get($key): mixed
            {
                throw new RuntimeException('The cache is down.');
            }

            public function many(array $keys): array
            {
                throw new RuntimeException('The cache is down.');
            }
        }));
        config()->set('cache.stores.down', ['driver' => 'down']);
        config()->set('dynamic-throttle.store', 'down');
        app()->forgetScopedInstances();
    }

    /**
     * The command's exit code and its JSON report.
     *
     * @param  array<string, mixed>  $options
     * @return array{int, array{status: string, checks: list<array{check: string, status: string, message: string}>}}
     */
    public static function report(array $options = []): array
    {
        $exitCode = Artisan::call('dynamic-throttle:health', ['--json' => true, ...$options]);
        /** @var array{status: string, checks: list<array{check: string, status: string, message: string}>} $report */
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return [$exitCode, $report];
    }

    /**
     * Each check's status and message, by id, in the report's order.
     *
     * @return array<string, array{string, string}>
     */
    public static function checks(): array
    {
        $checks = [];

        foreach (self::report()[1]['checks'] as $check) {
            $checks[$check['check']] = [$check['status'], $check['message']];
        }

        return $checks;
    }

    /**
     * One check's status and message.
     *
     * @return array{string, string}
     */
    public static function check(string $id): array
    {
        return self::checks()[$id] ?? throw new RuntimeException("The report has no check {$id}.");
    }

    public static function status(string $id): string
    {
        return self::check($id)[0];
    }

    public static function message(string $id): string
    {
        return self::check($id)[1];
    }

    /**
     * The command's text output, and its exit code.
     *
     * @param  array<string, mixed>  $options
     * @return array{string, int}
     */
    public static function text(array $options = []): array
    {
        $exitCode = Artisan::call('dynamic-throttle:health', $options);

        return [Artisan::output(), $exitCode];
    }

    /**
     * The text output's check lines, as [status, id, message], in order.
     *
     * @return list<array{string, string, string}>
     */
    public static function lines(string $output): array
    {
        preg_match_all('/^\s*(OK|WARNING|ERROR|SKIPPED)\s+(\S+)\s+(.+?)\s*$/m', $output, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [$match[1], $match[2], $match[3]], $matches);
    }
}
