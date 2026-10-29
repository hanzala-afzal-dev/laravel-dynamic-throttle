<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Docs;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;

/**
 * How long a Redis that hangs, accepting connections and never answering, holds a
 * request on the connections requests use: the counters' and the manifest's (issue
 * #99, ADR-0010 point 5). Without a timeout, phpredis waits PHP's
 * default_socket_timeout, 60 seconds by default, Predis 5 seconds to connect, and a
 * Predis read_write_timeout of 0 or less never gives up. It reads the config the way
 * Laravel's Redis connectors read it. It reports the timeouts, not a bound per call:
 * a client that reconnects or retries waits them again, such as Laravel's phpredis
 * connection, a cluster client trying each seed, or Predis's cluster client (the
 * review).
 *
 * @internal
 */
final readonly class RedisTimeoutsCheck implements Check
{
    /** Longer than this per call, a Redis that hangs holds requests until a busy server runs out of workers. */
    private const float LONGEST = 1.0;

    /** Predis's connect timeout when the connection sets none. */
    private const float PREDIS_CONNECT = 5.0;

    public function id(): string
    {
        return 'redis-timeouts';
    }

    public function run(HealthContext $context): CheckResult
    {
        if ($context->driver() instanceof InvalidArgumentException) {
            return CheckResult::skipped($this->id(), 'The config is invalid: see the config check.');
        }

        $predis = $context->config->get('database.redis.client') === 'predis';
        $named = [];
        $slow = [];
        $change = [];
        $longest = 0.0;
        $slowest = 0.0;

        foreach ($this->uses($context) as $connection => $uses) {
            $waits = $this->waits($context->config, $connection, $predis);

            if ($waits === null) {
                continue;
            }

            $name = "\"{$connection}\", for ".implode(' and ', $uses);
            $named[] = $name;
            $longest = $this->longer($longest, ...array_values($waits));
            $long = array_filter($waits, fn (?float $seconds): bool => $seconds === null || $seconds > self::LONGEST);

            if ($long !== []) {
                $change[] = "\"{$connection}\"";
                $slow[] = "{$name}, waits ".implode(' and ', array_map(
                    fn (string $what, ?float $seconds): string => ($seconds === null ? 'forever' : $this->seconds($seconds)).($what === 'connect' ? ' to connect' : ' for each reply'),
                    array_keys($long),
                    $long,
                ));
                $slowest = $this->longer($slowest, ...array_values($long));
            }
        }

        if ($named === []) {
            return CheckResult::skipped($this->id(), 'Neither the counters nor the manifest are on Redis.');
        }

        if ($slow === []) {
            return CheckResult::ok($this->id(), 'The Redis connections requests use time out within '.$this->seconds((float) $longest).': '.implode('; ', $named).'. A call can wait a few times that while the client reconnects and retries: see '.Docs::url('guide/storage-and-runtimes#timeouts').'.');
        }

        $holds = $slowest === null ? 'forever' : $this->seconds($slowest).' or more';

        // The connections to change, not "a connection for the package": the manifest's is the
        // cache store's, which a connection of the package's own leaves waiting (issue #151).
        return CheckResult::warning($this->id(), "A Redis that hangs holds a request {$holds} before its limits fail open: ".implode('; ', $slow).'. '
            .'Set "timeout" and "read_timeout" ("read_write_timeout" with Predis) to about 0.25 seconds on '.implode(' and ', $change).', in config/database.php: see '.Docs::url('guide/storage-and-runtimes#timeouts').'.');
    }

    /**
     * The Redis connections requests use, each with what it holds, in that order: the
     * counters, with the Redis driver or the cache driver on a redis store, and the
     * manifest, in the package's cache store.
     *
     * @return array<string, list<string>>
     */
    private function uses(HealthContext $context): array
    {
        [$driver, $target] = $context->container->make(LimiterStoreFactory::class)->driver();
        $store = $this->string($context->config, 'dynamic-throttle.store') ?? $this->string($context->config, 'cache.default');
        $uses = [];

        $counters = $driver === 'redis' ? $target : $this->storeConnection($context->config, $target);

        if ($counters !== null) {
            $uses[$counters][] = 'the counters';
        }

        $manifest = $this->storeConnection($context->config, $store);

        if ($manifest !== null) {
            $uses[$manifest][] = 'the manifest';
        }

        return $uses;
    }

    /**
     * The connection of a redis cache store, as Laravel's RedisStore picks it, or of a
     * failover store's first store, which every call tries first.
     */
    private function storeConnection(Config $config, ?string $store, int $depth = 0): ?string
    {
        $driver = $store === null ? null : $config->get("cache.stores.{$store}.driver");

        if ($driver === 'failover' && $depth < 5) {
            $stores = $config->get("cache.stores.{$store}.stores");
            $first = is_array($stores) ? reset($stores) : null;

            return is_string($first) ? $this->storeConnection($config, $first, $depth + 1) : null;
        }

        if ($driver !== 'redis') {
            return null;
        }

        return $this->string($config, "cache.stores.{$store}.connection") ?? 'default';
    }

    /**
     * How long a call on the connection waits to connect, and for each reply, in
     * seconds, null for forever. Null when the config has no such connection.
     *
     * @return array{connect: ?float, read: ?float}|null
     */
    private function waits(Config $config, string $name, bool $predis): ?array
    {
        $options = $this->settings($config->get('database.redis.options'));
        $single = in_array($name, ['client', 'options', 'clusters'], true) ? null : $config->get("database.redis.{$name}");

        if (is_array($single)) {
            $settings = $this->parsed($single);
            $own = $this->settings($single['options'] ?? null);

            // Predis's "parameters" option gives a connection the settings it lacks. Laravel
            // takes the connections' option's entry named after the connection, whole, else
            // the option itself, and the connection's own option replaces it.
            $parameters = $this->settings($options['parameters'] ?? null);
            $parameters = is_array($parameters[$name] ?? null) ? $this->settings($parameters[$name]) : $parameters;
            $parameters = array_key_exists('parameters', $own) ? $this->settings($own['parameters']) : $parameters;

            return $predis
                ? $this->predis($settings + $parameters)
                // Laravel merges the connections' options over a connection's settings, then its own.
                : $this->phpredis([...$settings, ...$options, ...$own]);
        }

        $cluster = $name === 'options' ? null : $config->get("database.redis.clusters.{$name}");

        if (! is_array($cluster)) {
            return null;
        }

        $merged = [...$options, ...$this->settings($config->get('database.redis.clusters.options')), ...$this->settings($cluster['options'] ?? null)];

        if (! $predis) {
            return $this->phpredis($merged);
        }

        $defaults = $this->settings($merged['parameters'] ?? null);
        $waits = ['connect' => 0.0, 'read' => 0.0];

        foreach ($cluster as $key => $node) {
            if (is_int($key) && is_array($node)) {
                $node = $this->predis($this->parsed($node) + $defaults);
                $waits = ['connect' => $this->longer($waits['connect'], $node['connect']), 'read' => $this->longer($waits['read'], $node['read'])];
            }
        }

        return $waits;
    }

    /**
     * phpredis: "timeout" to connect and "read_timeout" for each reply, where none or 0
     * means PHP's default_socket_timeout.
     *
     * @param  array<string, mixed>  $settings
     * @return array{connect: ?float, read: ?float}
     */
    private function phpredis(array $settings): array
    {
        return [
            'connect' => $this->positive($settings['timeout'] ?? null) ?? $this->socketTimeout(),
            'read' => $this->positive($settings['read_timeout'] ?? null) ?? $this->socketTimeout(),
        ];
    }

    /**
     * Predis: "timeout" to connect, 5 seconds without it, and "read_write_timeout" for
     * each reply, PHP's default_socket_timeout without it, and forever at 0 or less.
     *
     * @param  array<string, mixed>  $settings
     * @return array{connect: ?float, read: ?float}
     */
    private function predis(array $settings): array
    {
        $connect = $settings['timeout'] ?? null;
        $read = $settings['read_write_timeout'] ?? null;

        return [
            'connect' => is_numeric($connect) ? (float) $connect : self::PREDIS_CONNECT,
            'read' => is_numeric($read) ? $this->positive($read) : $this->socketTimeout(),
        ];
    }

    /**
     * A connection's settings, its url's included, as Laravel's RedisManager reads them.
     *
     * @param  array<mixed>  $connection
     * @return array<string, mixed>
     */
    private function parsed(array $connection): array
    {
        return $this->settings((new ConfigurationUrlParser)->parseConfiguration($this->settings($connection)));
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(mixed $values): array
    {
        $settings = [];

        foreach (is_array($values) ? $values : [] as $key => $value) {
            if (is_string($key)) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    private function positive(mixed $seconds): ?float
    {
        return is_numeric($seconds) && (float) $seconds > 0 ? (float) $seconds : null;
    }

    private function string(Config $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * PHP's default_socket_timeout, null for forever, as a negative value means.
     */
    private function socketTimeout(): ?float
    {
        $seconds = (float) ini_get('default_socket_timeout');

        return $seconds < 0 ? null : $seconds;
    }

    /**
     * The longer wait, where null is forever.
     */
    private function longer(?float ...$waits): ?float
    {
        return in_array(null, $waits, true) ? null : max([0.0, ...$waits]);
    }

    private function seconds(float $seconds): string
    {
        $shown = rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');

        return $shown.($shown === '1' ? ' second' : ' seconds');
    }
}
