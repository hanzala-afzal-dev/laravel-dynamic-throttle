<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\CounterKey;

covers(CounterKey::class);

it('keeps a valid rule id and identity hash', function (): void {
    $hash = hash('sha256', 'A');
    $key = new CounterKey('rule_2-b', $hash);

    expect($key->ruleId)->toBe('rule_2-b')
        ->and($key->identityHash)->toBe($hash);
});

it('accepts a 64-character rule id', function (): void {
    expect((new CounterKey(str_repeat('r', 64), hash('sha256', 'A')))->ruleId)->toHaveLength(64);
});

it('AC-13: rejects an invalid rule id or anything but a hash as the identity', function (string $ruleId, string $identityHash): void {
    expect(fn (): CounterKey => new CounterKey($ruleId, $identityHash))->toThrow(InvalidArgumentException::class);
})->with([
    'empty rule id' => ['', hash('sha256', 'A')],
    'rule id longer than 64' => [str_repeat('r', 65), hash('sha256', 'A')],
    'rule id with a colon' => ['1:2', hash('sha256', 'A')],
    'rule id with a space' => ['rule 1', hash('sha256', 'A')],
    // MySQL's default collation compares keys case-insensitively: "Login" would share "login"'s counter.
    'rule id with uppercase letters' => ['Login', hash('sha256', 'A')],
    'raw IP address' => ['1', '203.0.113.9'],
    'raw email address' => ['1', 'someone@example.com'],
    'uppercase hex' => ['1', strtoupper(hash('sha256', 'A'))],
    '63 hex characters' => ['1', substr(hash('sha256', 'A'), 1)],
    '65 hex characters' => ['1', hash('sha256', 'A').'0'],
]);
