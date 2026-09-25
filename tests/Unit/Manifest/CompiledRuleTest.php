<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(CompiledRule::class);

/*
| What CompiledRule::fromArray accepts and rejects, and the exact error it gives,
| pinned before decoding is made cheaper (roadmap, Phase 2): a manifest that
| decoded wrongly would apply the wrong limits (SPEC-006 AC-16).
*/

it('decodes the fields in any order, as JSON objects have no order', function (): void {
    $row = ManifestFixtures::rule(['scope' => 'header', 'scope_parameter' => 'X-Api-Key', 'response_override' => ['message' => 'Slow down']]);

    expect(CompiledRule::fromArray(array_reverse($row, true)))->toEqual(CompiledRule::fromArray($row))
        ->and(CompiledRule::fromArray(array_reverse($row, true))->toArray())->toBe($row);
});

it('rejects a rule without exactly the fields, naming them', function (array $row): void {
    expect(fn (): CompiledRule => CompiledRule::fromArray($row))->toThrow(new InvalidArgumentException(
        'A rule must have exactly the fields id, name, method, pattern, scope, scope_parameter, max_attempts, window_seconds, priority, mode, on_failure, on_missing_identity, response_override.',
    ));
})->with(function (): iterable {
    $row = ManifestFixtures::rule();

    yield 'a missing field' => [array_diff_key($row, ['priority' => true])];
    yield 'an extra field' => [[...$row, 'description' => 'x']];
    yield 'a misspelled field' => [[...array_diff_key($row, ['priority' => true]), 'priorty' => 100]];
    yield 'a list of the values' => [array_values($row)];
    yield 'no fields' => [[]];
});

it('names the field whose value has the wrong type or is unknown', function (string $field, mixed $value, string $message): void {
    expect(fn (): CompiledRule => CompiledRule::fromArray([...ManifestFixtures::rule(), $field => $value]))
        ->toThrow(new InvalidArgumentException($message));
})->with([
    'an integer id' => ['id', 1, 'id must be a string, got int.'],
    'a null name' => ['name', null, 'name must be a string, got null.'],
    'an unknown method' => ['method', 'FETCH', 'method must be one of *, GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS, got "FETCH".'],
    'a lower-case method' => ['method', 'get', 'method must be one of *, GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS, got "get".'],
    'a method that is not a string' => ['method', ['GET'], 'method must be one of *, GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS, got array.'],
    'a pattern that is not a string' => ['pattern', 5, 'pattern must be a string, got int.'],
    'an unknown scope' => ['scope', 'planet', 'scope must be one of ip, user, api_token, header, global, custom, got "planet".'],
    'a scope that is not a string' => ['scope', null, 'scope must be one of ip, user, api_token, header, global, custom, got null.'],
    'a scope parameter that is not a string' => ['scope_parameter', 7, 'scope_parameter must be a string, got int.'],
    'a string max' => ['max_attempts', '60', 'max_attempts must be an integer, got string.'],
    'a float window' => ['window_seconds', 60.0, 'window_seconds must be an integer, got float.'],
    'a boolean priority' => ['priority', true, 'priority must be an integer, got bool.'],
    'an unknown mode' => ['mode', 'maybe', 'mode must be one of enforce, dry_run, got "maybe".'],
    'an unknown failure policy' => ['on_failure', 'sometimes', 'on_failure must be one of open, closed, got "sometimes".'],
    'an unknown missing-identity policy' => ['on_missing_identity', 'guess', 'on_missing_identity must be one of ip, skip, got "guess".'],
    'a response override that is a string' => ['response_override', 'Slow down', 'response_override must be a JSON object.'],
    'a response override that is a list' => ['response_override', ['Slow down'], 'response_override must be a JSON object.'],
    'an integer response override' => ['response_override', 429, 'response_override must be a JSON object.'],
]);

it('names the first bad field in the order the manifest writes them', function (): void {
    $row = [...ManifestFixtures::rule(), 'on_failure' => 'sometimes', 'max_attempts' => '60', 'name' => 7];

    expect(fn (): CompiledRule => CompiledRule::fromArray($row))->toThrow(new InvalidArgumentException('name must be a string, got int.'));
});

it('checks the types before the rules on the values', function (): void {
    $row = [...ManifestFixtures::rule(), 'id' => 'Rule 1', 'priority' => '1'];

    expect(fn (): CompiledRule => CompiledRule::fromArray($row))->toThrow(new InvalidArgumentException('priority must be an integer, got string.'));
});

it('accepts null for the optional fields, and an empty response override', function (): void {
    $rule = CompiledRule::fromArray([...ManifestFixtures::rule(), 'scope_parameter' => null, 'response_override' => []]);

    expect([$rule->scopeParameter, $rule->responseOverride])->toBe([null, []]);
});
