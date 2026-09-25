<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle;

use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;
use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Contracts\ScopeResolver;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * The package's entry point for apps, behind the DynamicThrottle facade. It
 * lives for the worker: apps register their scopes once, at boot.
 */
final class DynamicThrottle
{
    /** The in-memory cache store fake() adds to the app's cache config. */
    public const string FAKE_STORE = 'dynamic-throttle-fake';

    /** @var array<string, Closure|ScopeResolver|class-string<ScopeResolver>> */
    private array $scopes = [];

    /**
     * Registers a custom scope (ADR-0009). Rules with the custom scope $name count
     * each request under what the resolver returns for it: a string, an integer or
     * a Stringable, or null (or '') when the request has none.
     *
     * A class name is resolved from the container for each request that needs it.
     * Registering a name again replaces its resolver.
     *
     * @param  Closure|ScopeResolver|class-string<ScopeResolver>  $resolver  a closure receives the request
     *
     * @throws InvalidArgumentException for a name that is not 1 to 64 characters from a-z,
     *                                  0-9, "_" and "-", or a class that is not a ScopeResolver
     */
    public function scope(string $name, Closure|ScopeResolver|string $resolver): void
    {
        // The rule ids' alphabet: names are stored in rules' scope_parameter.
        if (preg_match('/\A[a-z0-9_-]{1,64}\z/', $name) !== 1) {
            throw new InvalidArgumentException("A scope name must be 1 to 64 characters from a-z, 0-9, \"_\" and \"-\", got \"{$name}\".");
        }

        if (is_string($resolver) && ! is_subclass_of($resolver, ScopeResolver::class)) {
            throw new InvalidArgumentException("The \"{$name}\" scope needs a closure or a ScopeResolver, and {$resolver} is not one.");
        }

        $this->scopes[$name] = $resolver;
    }

    /**
     * @internal
     *
     * @return Closure|ScopeResolver|class-string<ScopeResolver>|null
     */
    public function scopeResolver(string $name): Closure|ScopeResolver|string|null
    {
        return $this->scopes[$name] ?? null;
    }

    /**
     * For the app's tests (ADR-0020 point 12, SPEC-010): moves the counters and the
     * manifest into a fresh in-memory cache store, so tests need no Redis and share
     * no counts. The counters read Laravel's clock, so travel() moves their windows.
     *
     * Rules still come from the rules table. The ones already saved are compiled
     * now, so they apply from the first request, and the manifest this process
     * kept is forgotten, so a test never sees the rules of the test before it.
     * Without the package's tables, no rule applies.
     */
    public function fake(): void
    {
        $app = Container::getInstance();
        $config = $app->make(Config::class);

        $config->set('cache.stores.'.self::FAKE_STORE, ['driver' => 'array', 'serialize' => false]);
        $config->set('dynamic-throttle.store', self::FAKE_STORE);
        $config->set('dynamic-throttle.driver', 'cache');

        // Built on the store the fake replaces, so they are built again on the fake one.
        $app->forgetInstance(LimiterStore::class);
        $app->forgetInstance(RuleEvaluator::class);
        $app->forgetInstance(ManifestRepository::class);
        ManifestRepository::flushState();

        try {
            $app->make(ManifestCompiler::class)->compile();
        } catch (ManifestCompileFailed) {
            // No rules table: a manifest without rules, so none applies and no request reports it missing.
            $app->make(CacheFactory::class)->store(self::FAKE_STORE)->forever($app->make(KeyBuilder::class)->manifest(), ManifestCodec::encode(1, []));
        }
    }
}
