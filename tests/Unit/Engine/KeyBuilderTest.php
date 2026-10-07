<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;

covers(KeyBuilder::class);

it('AC-12: builds the window key from the prefix, rule, window, identity hash and window index', function (): void {
    $hash = hash('sha256', 'A');

    expect((new KeyBuilder('dt:'))->counter(new CounterKey('42', $hash), 60, 30_000_000))
        ->toBe("dt:c:42:60:{$hash}:30000000");
});

it('SPEC-003 AC-15: builds the counter base key, and the window key from it', function (): void {
    $keys = new KeyBuilder('dt:');
    $hash = hash('sha256', 'A');
    $key = new CounterKey('42', $hash);

    expect($keys->counterBase($key, 60))->toBe("dt:c:42:60:{$hash}")
        ->and($keys->counter($key, 60, 30_000_000))->toBe($keys->counterBase($key, 60).':30000000');
});

it('AC-12: accepts an empty prefix and the default one', function (): void {
    $key = new CounterKey('1', hash('sha256', 'A'));

    expect((new KeyBuilder(''))->counter($key, 60, 1))->toStartWith('c:1:60:')
        ->and((new KeyBuilder)->counter($key, 60, 1))->toStartWith('dt:c:1:60:');
});

it('AC-12: accepts a 32-character prefix made of allowed characters', function (): void {
    $prefix = 'Az09:_.-'.str_repeat('x', 24);

    expect((new KeyBuilder($prefix))->prefix)->toBe($prefix);
});

it('AC-12: rejects an unsafe prefix', function (string $prefix): void {
    expect(fn (): KeyBuilder => new KeyBuilder($prefix))->toThrow(InvalidArgumentException::class);
})->with([
    '33 characters' => [str_repeat('x', 33)],
    'a space' => ['my app:'],
    'a newline' => ["dt:\n"],
    'a slash' => ['dt/'],
    'a unicode letter' => ['dü:'],
]);

it('SPEC-006: builds the manifest keys from the prefix, apart from every counter key', function (): void {
    $keys = new KeyBuilder('dt:');

    expect([$keys->manifest(), $keys->manifestLock(), $keys->manifestRebuild()])->toBe(['dt:m', 'dt:m:lock', 'dt:m:rebuild'])
        ->and($keys->counterBase(new CounterKey('1', hash('sha256', 'A')), 60))->toStartWith('dt:c:');
});

it('SPEC-012: builds the keys of the cooldown and of the reported causes under the prefix, for one app', function (): void {
    $keys = new KeyBuilder('dt:');

    // APCu is shared by the apps of a server, so its keys name the app too.
    expect([$keys->cooldown('app1'), $keys->reported('app1', '0a1b')])->toBe(['dt:cooldown:app1', 'dt:reported:app1:0a1b']);
});

it('SPEC-028: builds the key of the manifest in APCu under the prefix, for one app and one version of the package', function (): void {
    // A server's apps share APCu, and so do the versions a deploy in place runs.
    expect((new KeyBuilder('dt:'))->localManifest('app1', '0865f7e'))->toBe('dt:manifest:app1:0865f7e');
});

it('SPEC-028 AC-15: builds the key of the claim on the refresh of the manifest in APCu, next to the manifest\'s', function (): void {
    expect((new KeyBuilder('dt:'))->localManifestRefresh('app1', '0865f7e'))->toBe('dt:manifest:app1:0865f7e:refresh');
});

it('SPEC-015: builds the telemetry keys under the prefix, apart from the counters and the manifest', function (): void {
    $keys = new KeyBuilder('dt:');

    expect([
        $keys->telemetryMinute(30_000_000),
        $keys->telemetryCount(30_000_000, '12', 'hits'),
        $keys->telemetryCount(30_000_000, '12', 'would_blocks'),
        $keys->offenders('12', 500_000),
        $keys->clients('12', 500_000),
        $keys->telemetryFlushed(),
    ])->toBe([
        'dt:t:30000000',
        'dt:t:30000000:12:hits',
        'dt:t:30000000:12:would_blocks',
        'dt:t:o:12:500000',
        'dt:t:u:12:500000',
        'dt:t:flushed',
    ]);
});

it('SPEC-015: rejects a telemetry metric other than hits, blocks and would_blocks', function (): void {
    expect(fn (): string => (new KeyBuilder('dt:'))->telemetryCount(30_000_000, '12', 'misses'))->toThrow(InvalidArgumentException::class);
});
