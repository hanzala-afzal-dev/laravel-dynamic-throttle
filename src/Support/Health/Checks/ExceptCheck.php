<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;

/**
 * The paths of installed packages that except does not cover, so a rule can limit
 * them (SPEC-016 AC-9, ADR-0020 point 3): the packages whose defaults except
 * lists, at the paths their own config gives. This is the only place the package
 * reads those keys (decision 8).
 *
 * @internal
 */
final readonly class ExceptCheck implements Check
{
    /** The packages served under a path of their config, which except must cover whole: name, config key, default path. */
    private const array PACKAGES = [
        ['Horizon', 'horizon.path', 'horizon'],
        ['Telescope', 'telescope.path', 'telescope'],
        ['Pulse', 'pulse.path', 'pulse'],
        ['Nova', 'nova.path', 'nova'],
    ];

    public function id(): string
    {
        return 'except';
    }

    public function run(HealthContext $context): CheckResult
    {
        $patterns = $this->patterns($context->config);
        $checked = [];
        $missing = [];

        foreach (self::PACKAGES as [$name, $key, $default]) {
            if (! $this->installed($context->config, $key)) {
                continue;
            }

            $path = $this->path($context->config->get($key), $default);
            $checked[] = $name;
            $add = [...($this->matches($patterns, $path) ? [] : [$path]), ...($this->coversBelow($patterns, $path) ? [] : ["{$path}/*"])];

            if ($add !== []) {
                $missing[] = "{$name} at \"{$path}\": add ".$this->quoted($add);
            }
        }

        // Nova's API is at nova-api, whatever nova.path says.
        if ($this->installed($context->config, 'nova.path') && ! $this->coversBelow($patterns, 'nova-api')) {
            $missing[] = 'Nova\'s API at "nova-api": add "nova-api/*"';
        }

        if ($this->installed($context->config, 'sanctum.prefix')) {
            $path = $this->path($context->config->get('sanctum.prefix'), 'sanctum').'/csrf-cookie';
            $checked[] = 'Sanctum';

            if (! $this->matches($patterns, $path)) {
                $missing[] = "Sanctum's CSRF cookie at \"{$path}\": add \"{$path}\"";
            }
        }

        if ($missing !== []) {
            return CheckResult::warning($this->id(), 'A rule can limit these packages, whose paths except does not cover. '.implode('; ', $missing).', to dynamic-throttle.except.');
        }

        return CheckResult::ok($this->id(), $checked === []
            ? 'No package whose paths except keeps from the rules is installed.'
            : 'except covers the paths of '.implode(', ', $checked).'.');
    }

    /**
     * A package is installed when its config is loaded.
     */
    private function installed(Config $config, string $key): bool
    {
        return is_array($config->get(explode('.', $key, 2)[0]));
    }

    /**
     * A path as except patterns match it: without its slashes at either end.
     */
    private function path(mixed $configured, string $default): string
    {
        $path = is_string($configured) ? trim($configured, '/') : '';

        return $path === '' ? $default : $path;
    }

    /**
     * except's patterns, as the middleware matches them: a leading slash is ignored,
     * and the root stays "/".
     *
     * @return list<string>
     */
    private function patterns(Config $config): array
    {
        $patterns = [];
        $except = $config->get('dynamic-throttle.except');

        // As Untouched reads it: anything but a list of patterns covers nothing.
        foreach (is_array($except) ? $except : [] as $pattern) {
            if (is_string($pattern)) {
                $trimmed = ltrim($pattern, '/');
                $patterns[] = $trimmed === '' ? '/' : $trimmed;
            }
        }

        return $patterns;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a pattern matches every path below the path: one that ends with "*",
     * and whose part before it matches the start of "<path>/", which the "*" then
     * extends to any path below.
     *
     * @param  list<string>  $patterns
     */
    private function coversBelow(array $patterns, string $path): bool
    {
        $below = "{$path}/";

        foreach ($patterns as $pattern) {
            if (! str_ends_with($pattern, '*')) {
                continue;
            }

            $head = substr($pattern, 0, -1);

            for ($length = 0; $length <= strlen($below); $length++) {
                if (Str::is($head, substr($below, 0, $length))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  non-empty-list<string>  $patterns
     */
    private function quoted(array $patterns): string
    {
        return '"'.implode('" and "', $patterns).'"';
    }
}
