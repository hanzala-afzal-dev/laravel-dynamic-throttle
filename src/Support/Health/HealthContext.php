<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Throwable;

/**
 * What several health checks read, read once for the report: so far the counter
 * driver (SPEC-016). It only reads.
 *
 * @internal
 */
final class HealthContext
{
    private string|InvalidArgumentException|null $driver = null;

    public function __construct(
        public readonly Container $container,
        public readonly Config $config,
        private readonly Clock $clock,
    ) {}

    /**
     * Unix seconds, by the package's clock.
     */
    public function now(): int
    {
        return intdiv($this->clock->microseconds(), 1_000_000);
    }

    /**
     * The counter driver as dynamic-throttle:status describes it, such as "cache, store
     * array", or why the config cannot build it (ADR-0020 point 1).
     */
    public function driver(): string|InvalidArgumentException
    {
        if ($this->driver === null) {
            try {
                $this->driver = $this->container->make(LimiterStoreFactory::class)->describe();
            } catch (InvalidArgumentException $exception) {
                $this->driver = $exception;
            }
        }

        return $this->driver;
    }

    /**
     * An error's message, with its root cause's when it does not already say it, as
     * explain gives it.
     */
    public static function message(Throwable $exception): string
    {
        $root = $exception;

        while (($previous = $root->getPrevious()) instanceof Throwable) {
            $root = $previous;
        }

        $message = $exception->getMessage();

        return $root === $exception || str_contains($message, $root->getMessage()) ? $message : $message.' '.$root->getMessage();
    }
}
