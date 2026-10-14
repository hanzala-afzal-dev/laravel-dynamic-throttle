<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Http\Dashboard\DashboardRoutes;
use Afzal\DynamicThrottle\Support\ConfigValues;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Afzal\DynamicThrottle\Support\RouteMiddleware;
use InvalidArgumentException;

/**
 * The config (SPEC-016 AC-4): an error when the counter driver cannot be built
 * from it, as dynamic-throttle:status reports it (ADR-0020 point 1), and a
 * warning for what it asks that the package ignores: the kill switch, a value
 * replaced with its default (decision 14), a group auto_attach cannot find, or a
 * middleware.after entry that names nothing (SPEC-010).
 *
 * @internal
 */
final readonly class ConfigCheck implements Check
{
    public function id(): string
    {
        return 'config';
    }

    public function run(HealthContext $context): CheckResult
    {
        $driver = $context->driver();

        if ($driver instanceof InvalidArgumentException) {
            return CheckResult::error($this->id(), 'The config is invalid: '.$driver->getMessage());
        }

        $problems = [...$this->killSwitch($context), ...$this->replaced($context), ...$this->groups($context), ...$this->after($context), ...$this->dashboardPath($context)];

        return $problems === []
            ? CheckResult::ok($this->id(), "The config is valid. The counters: {$driver}.")
            : CheckResult::warning($this->id(), implode(' ', $problems));
    }

    /**
     * @return list<string>
     */
    private function killSwitch(HealthContext $context): array
    {
        return $context->config->get('dynamic-throttle.enabled') === true ? [] : ['dynamic-throttle.enabled is off, so the middleware does nothing.'];
    }

    /**
     * @return list<string>
     */
    private function dashboardPath(HealthContext $context): array
    {
        return $context->config->get('dynamic-throttle.dashboard.enabled') !== false && DashboardRoutes::path($context->config) === null
            ? ['dynamic-throttle.dashboard.path is empty, so the dashboard is not served: it would take the site\'s root.']
            : [];
    }

    /**
     * Each value the package uses something else for, such as a sample rate written as
     * a string.
     *
     * @return list<string>
     */
    private function replaced(HealthContext $context): array
    {
        $problems = [];

        foreach (ConfigValues::all() as $key => $value) {
            $configured = $context->config->get("dynamic-throttle.{$key}");
            $used = $value($configured);

            if (! $this->same($configured, $used)) {
                $problems[] = "dynamic-throttle.{$key} is {$this->json($configured)}, so the package uses {$this->json($used)}.";
            }
        }

        return $problems;
    }

    /**
     * A whole number the package uses as a float, such as a sample rate of 1, is the
     * same number.
     */
    private function same(mixed $configured, mixed $used): bool
    {
        return $configured === $used || (is_int($configured) && is_float($used) && (float) $configured === $used);
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : get_debug_type($value);
    }

    /**
     * Groups auto_attach names that the app does not define, which the package skips
     * (SPEC-010 AC-3).
     *
     * @return list<string>
     */
    private function groups(HealthContext $context): array
    {
        $groups = $context->routes()->router()->getMiddlewareGroups();
        $problems = [];

        foreach ($this->strings($context, 'dynamic-throttle.middleware.auto_attach') as $group) {
            if (! array_key_exists($group, $groups)) {
                $problems[] = "dynamic-throttle.middleware.auto_attach names \"{$group}\", which is no middleware group of the app, so it attaches nothing.";
            }
        }

        return $problems;
    }

    /**
     * middleware.after entries that are neither an alias nor a class: the package
     * cannot sort itself after them (ADR-0020 point 4).
     *
     * @return list<string>
     */
    private function after(HealthContext $context): array
    {
        $aliases = $context->routes()->router()->getMiddleware();
        $problems = [];

        foreach ($this->strings($context, 'dynamic-throttle.middleware.after') as $entry) {
            $name = RouteMiddleware::className($entry);

            if (! array_key_exists($name, $aliases) && ! class_exists($name) && ! interface_exists($name)) {
                $problems[] = "dynamic-throttle.middleware.after names \"{$entry}\", which is neither a middleware alias nor a class, so dynamic.throttle is not sorted after it.";
            }
        }

        return $problems;
    }

    /**
     * A config list's non-empty strings, as the service provider reads it.
     *
     * @return list<string>
     */
    private function strings(HealthContext $context, string $key): array
    {
        $values = $context->config->get($key);

        return is_array($values)
            ? array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && $value !== ''))
            : [];
    }
}
