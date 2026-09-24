<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Contracts\ManifestRebuilder;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Models\Rule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Compiles the rules table into the manifest requests read (ADR-0007, SPEC-006).
 * Runs on admin actions, the command line, the scheduler, and after the response
 * of a request that found the manifest missing. Never before a response.
 */
final readonly class ManifestCompiler implements ManifestRebuilder
{
    public function __construct(
        private Repository $cache,
        private KeyBuilder $keys,
        private int $lockSeconds = 30,
        private int $lockWaitSeconds = 5,
    ) {}

    public function rebuild(): void
    {
        $this->compile();
    }

    /**
     * @throws ManifestCompileFailed with nothing written
     */
    public function compile(): CompileResult
    {
        try {
            $store = $this->cache->getStore();

            // A store without locks, such as APCu, is per-server anyway (SPEC-006).
            if (! $store instanceof LockProvider) {
                return $this->compileNow();
            }

            $result = $store->lock($this->keys->manifestLock(), $this->lockSeconds)
                ->block($this->lockWaitSeconds, fn (): CompileResult => $this->compileNow());

            // block() returns the callback's result, which Laravel types as mixed.
            return $result instanceof CompileResult ? $result : throw new RuntimeException('The compile lock returned no result.');
        } catch (Throwable $exception) {
            throw ManifestCompileFailed::because($exception);
        }
    }

    private function compileNow(): CompileResult
    {
        $rows = Rule::query()->where('enabled', true)->orderBy('priority')->orderBy('id')->toBase()->get();
        $rules = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $row = (array) $row;

            try {
                $rules[] = CompiledRule::fromArray($this->fromRow($row));
            } catch (InvalidArgumentException $exception) {
                $skipped++;
                $id = is_int($row['id'] ?? null) || is_string($row['id'] ?? null) ? $row['id'] : '?';
                report(new InvalidRuleSkipped("Rule {$id} was skipped: ".$exception->getMessage(), previous: $exception));
            }
        }

        // Greater than every earlier version, even after the cache was flushed (SPEC-006 AC-3).
        $version = max($this->storedVersion() + 1, (int) Date::now()->getTimestampMs());

        if (! $this->cache->forever($this->keys->manifest(), ManifestCodec::encode($version, $rules))) {
            throw new RuntimeException('The cache store did not accept the manifest.');
        }

        return new CompileResult($version, count($rules), $skipped);
    }

    private function storedVersion(): int
    {
        $json = $this->cache->get($this->keys->manifest());

        try {
            return is_string($json) ? ManifestCodec::decode($json)->version : 0;
        } catch (InvalidManifest) {
            return 0;
        }
    }

    /**
     * A database row in the manifest's shape. Drivers disagree on types (MySQL and
     * SQLite can return numbers as strings), so numbers are normalized here and
     * everything else is left to CompiledRule to judge.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>
     */
    private function fromRow(array $row): array
    {
        return [
            'id' => is_int($row['id']) ? (string) $row['id'] : $row['id'],
            'name' => $row['name'],
            'method' => $row['method'],
            'pattern' => $row['pattern'],
            'scope' => $row['scope'],
            'scope_parameter' => $row['scope_parameter'],
            'max_attempts' => $this->integer($row, 'max_attempts'),
            'window_seconds' => $this->integer($row, 'window_seconds'),
            'priority' => $this->integer($row, 'priority'),
            'mode' => $row['mode'],
            'on_failure' => $row['on_failure'],
            'on_missing_identity' => $row['on_missing_identity'],
            'response_override' => $this->responseOverride($row['response_override']),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function integer(array $row, string $field): mixed
    {
        $value = $row[$field];

        return is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1 ? (int) $value : $value;
    }

    private function responseOverride(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('response_override must be a JSON object, and is not valid JSON.');
        }
    }
}
