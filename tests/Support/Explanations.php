<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * dynamic-throttle:explain and what it reads (SPEC-014).
 */
final class Explanations
{
    /**
     * Runs the command with --json, and decodes its output, which must be one JSON
     * object and nothing else (AC-10).
     *
     * @param  array<string, mixed>  $options  such as ['--ip' => '203.0.113.7', '--header' => ['X-Api-Key: abc']]
     * @return array<string, mixed>
     */
    public static function json(string $method, string $path, array $options = []): array
    {
        $exitCode = Artisan::call('dynamic-throttle:explain', ['method' => $method, 'path' => $path, ...$options, '--json' => true]);
        $output = Artisan::output();

        if ($exitCode !== 0) {
            throw new RuntimeException("dynamic-throttle:explain exited with {$exitCode}: {$output}");
        }

        $explanation = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($explanation)) {
            throw new RuntimeException("dynamic-throttle:explain printed no JSON object: {$output}");
        }

        /** @var array<string, mixed> $explanation */
        return $explanation;
    }

    /**
     * The explanation's rules, by id, in its order.
     *
     * @param  array<string, mixed>  $explanation
     * @return array<string, array<string, mixed>>
     */
    public static function rules(array $explanation): array
    {
        $rules = [];

        foreach (is_array($explanation['rules'] ?? null) ? $explanation['rules'] : [] as $rule) {
            if (is_array($rule) && is_string($rule['id'] ?? null)) {
                /** @var array<string, mixed> $rule */
                $rules[$rule['id']] = $rule;
            }
        }

        return $rules;
    }

    /**
     * The identity hashes the array store holds counters under, for a rule: what the
     * middleware counted (AC-2).
     *
     * @return list<string>
     */
    public static function counterHashes(string $ruleId): array
    {
        $hashes = [];

        foreach (array_keys(self::arrayCache()) as $key) {
            if (preg_match('/\Adt:c:'.preg_quote($ruleId, '/').':\d+:([0-9a-f]{64}):\d+\z/', (string) $key, $matches) === 1) {
                $hashes[] = $matches[1];
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * Everything the array store holds, by key: the manifest and every counter (AC-9).
     *
     * @return array<array-key, mixed>
     */
    public static function arrayCache(): array
    {
        $store = Cache::store('array')->getStore();

        if (! $store instanceof ArrayStore) {
            throw new RuntimeException('The array cache store is not an ArrayStore.');
        }

        /** @var array<array-key, mixed> */
        return (fn (): array => $this->storage)->call($store);
    }

    /**
     * A users table with these ids, which the default guard's Eloquent provider reads
     * (Illuminate\Foundation\Auth\User in Testbench).
     */
    public static function users(int ...$ids): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });

        foreach ($ids as $id) {
            DB::table('users')->insert(['id' => $id]);
        }
    }
}
