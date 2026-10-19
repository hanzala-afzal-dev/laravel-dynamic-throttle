<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Afzal\DynamicThrottle\Support\Health\Runtime;

/**
 * Whether the web server's PHP shares the rules and the cooldown between requests
 * (issue #100). On PHP-FPM, and any SAPI that starts each request afresh, only
 * APCu does that (SPEC-028, ADR-0010 point 4). Octane's workers keep them in their
 * memory. The command line cannot see the web server's PHP, where APCu is usually
 * off anyway, so the check says so there: the dashboard runs it in the web server.
 *
 * @internal
 */
final readonly class ApcuCheck implements Check
{
    public function id(): string
    {
        return 'apcu';
    }

    public function run(HealthContext $context): CheckResult
    {
        $runtime = $context->container->bound(Runtime::class) ? $context->container->make(Runtime::class) : Runtime::current();

        if ($runtime->octane) {
            return CheckResult::ok($this->id(), 'On Octane, each worker keeps the rules and the counter store\'s cooldown in its memory.');
        }

        if (in_array($runtime->sapi, ['cli', 'phpdbg'], true)) {
            return CheckResult::skipped($this->id(), 'The command line cannot see the PHP your web server runs: the dashboard\'s Settings page runs this check there.');
        }

        $php = $runtime->sapi === 'fpm-fcgi' ? 'PHP-FPM' : "PHP ({$runtime->sapi})";

        return $runtime->apcu
            ? CheckResult::ok($this->id(), "{$php}'s workers share the rules and the counter store's cooldown in APCu.")
            : CheckResult::warning($this->id(), "{$php} runs without APCu, so every request reads the rules from the cache store first, and the workers do not share the counter store's cooldown: during an outage, every request tries the store again. Install and enable the APCu extension (docs/guide/storage-and-runtimes.md#runtimes).");
    }
}
