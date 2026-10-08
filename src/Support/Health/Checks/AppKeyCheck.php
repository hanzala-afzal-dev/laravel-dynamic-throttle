<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;

/**
 * Whether identities are hashed with the app's key (SPEC-016 AC-7, ADR-0024).
 *
 * @internal
 */
final readonly class AppKeyCheck implements Check
{
    public function id(): string
    {
        return 'app-key';
    }

    public function run(HealthContext $context): CheckResult
    {
        $key = $context->config->get('app.key');

        return is_string($key) && $key !== ''
            ? CheckResult::ok($this->id(), 'Identities are hashed with the app\'s key.')
            : CheckResult::warning($this->id(), 'APP_KEY is empty, so identities are hashed without a key, and anyone who knows a client\'s IP or user id can tell which counter is theirs (ADR-0024). Run php artisan key:generate.');
    }
}
