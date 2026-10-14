<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Http\Dashboard\Validation\RuleInput;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| SPEC-018 AC-12 to AC-14: what the dashboard and its API accept for a rule,
| so a rule saved through them always compiles, and the 500 enabled rules
| limit. The app registers one custom scope, "tenant".
*/

beforeEach(function (): void {
    Throttling::prepare();
    DynamicThrottle::scope('tenant', fn (): string => 'acme');
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * A rule's valid input, with the overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ruleInput(array $overrides = []): array
{
    return [...['name' => 'API', 'pattern' => 'api/*', 'scope' => 'ip', 'max_attempts' => 60, 'window_seconds' => 60], ...$overrides];
}

/**
 * The fields the validation refuses, by name, or [] when it accepts the input.
 *
 * @param  array<string, mixed>  $input
 * @return list<string>
 */
function refusedFields(array $input, ?Rule $rule = null): array
{
    try {
        app(RuleInput::class)->validate($input, $rule);
    } catch (ValidationException $exception) {
        return array_keys($exception->errors());
    }

    return [];
}

/**
 * Rules written straight to the table, so 500 of them compile nothing.
 */
function insertEnabledRules(int $count): void
{
    DB::table('dynamic_throttle_rules')->insert(array_fill(0, $count, ['name' => 'Bulk', 'pattern' => 'bulk/*', 'scope' => 'ip', 'max_attempts' => 60, 'window_seconds' => 60, 'enabled' => true]));
}

it('AC-12: accepts a rule at each boundary, and the rule it saves compiles', function (array $overrides): void {
    $rule = Rule::create(app(RuleInput::class)->validate(ruleInput($overrides)));

    expect(array_column(Exemptions::stored()['rules'], 'id'))->toContain((string) $rule->id);
})->with([
    '1 attempt' => [['max_attempts' => 1]],
    '4,294,967,295 attempts' => [['max_attempts' => 4_294_967_295]],
    '1 second' => [['window_seconds' => 1]],
    '86,400 seconds' => [['window_seconds' => 86_400]],
    'a 255-character pattern' => [['pattern' => str_repeat('a', 255)]],
    'a 100-character name' => [['name' => str_repeat('n', 100)]],
    'priority 0' => [['priority' => 0]],
    'the highest priority' => [['priority' => 4_294_967_295]],
    'a 4,096-byte override' => [['response_override' => ['message' => str_repeat('x', 4_096 - strlen('{"message":""}'))]]],
    'an empty override' => [['response_override' => []]],
    'a header rule' => [['scope' => 'header', 'scope_parameter' => 'X-Api-Key']],
    'a custom rule with a registered scope' => [['scope' => 'custom', 'scope_parameter' => 'tenant']],
    'every enum' => [['method' => 'POST', 'mode' => 'dry_run', 'on_failure' => 'closed', 'on_missing_identity' => 'skip']],
    'a description with lines' => [['description' => "Limits the API.\nRaised for the launch."]],
]);

it('AC-13: refuses each invalid input, naming its field', function (array $overrides, string $field): void {
    expect(refusedFields(ruleInput($overrides)))->toBe([$field]);
})->with([
    'an empty name' => [['name' => ''], 'name'],
    'a 101-character name' => [['name' => str_repeat('n', 101)], 'name'],
    'a name with a terminal escape' => [['name' => "API\e[2K"], 'name'],
    'a 1,001-character description' => [['description' => str_repeat('d', 1_001)], 'description'],
    'an unknown method' => [['method' => 'FETCH'], 'method'],
    'an unknown scope' => [['scope' => 'planet'], 'scope'],
    'an unknown mode' => [['mode' => 'shadow'], 'mode'],
    'a pattern with a space' => [['pattern' => 'api/ orders'], 'pattern'],
    'a 256-character pattern' => [['pattern' => str_repeat('a', 256)], 'pattern'],
    '0 attempts' => [['max_attempts' => 0], 'max_attempts'],
    '4,294,967,296 attempts' => [['max_attempts' => 4_294_967_296], 'max_attempts'],
    'attempts as text' => [['max_attempts' => 'sixty'], 'max_attempts'],
    '0 seconds' => [['window_seconds' => 0], 'window_seconds'],
    '86,401 seconds' => [['window_seconds' => 86_401], 'window_seconds'],
    'a negative priority' => [['priority' => -1], 'priority'],
    'an override that is a list' => [['response_override' => ['a', 'b']], 'response_override'],
    'a 4,097-byte override' => [['response_override' => ['message' => str_repeat('x', 4_097 - strlen('{"message":""}'))]], 'response_override'],
    'a header rule without a header name' => [['scope' => 'header'], 'scope_parameter'],
    'a header name with a space' => [['scope' => 'header', 'scope_parameter' => 'X Bad'], 'scope_parameter'],
    'a custom rule with a scope the app did not register' => [['scope' => 'custom', 'scope_parameter' => 'planet'], 'scope_parameter'],
    'an ip rule with a scope parameter' => [['scope' => 'ip', 'scope_parameter' => 'X-Api-Key'], 'scope_parameter'],
    'enabled as text' => [['enabled' => 'sometimes'], 'enabled'],
]);

it('AC-13: checks a change against the rule it changes, as a whole', function (): void {
    $rule = Rule::create(ruleInput());

    $changed = app(RuleInput::class)->validate(['max_attempts' => 5], $rule);

    expect(refusedFields(['scope' => 'header'], $rule))->toBe(['scope_parameter'])
        ->and([$changed['max_attempts'], $changed['pattern'], $changed['scope']])->toBe([5, 'api/*', 'ip']);
});

it('AC-14: refuses a new enabled rule, and enabling one, when 500 rules are enabled, and accepts a disabled one', function (): void {
    insertEnabledRules(500);
    $disabled = Rule::create(ruleInput(['enabled' => false]));

    try {
        app(RuleInput::class)->validate(ruleInput());
        $message = null;
    } catch (ValidationException $exception) {
        $message = $exception->errors()['enabled'][0] ?? null;
    }

    expect($message)->toBe('500 rules are enabled, the most the dashboard allows.')
        ->and(refusedFields(['enabled' => true], $disabled))->toBe(['enabled'])
        ->and(refusedFields(ruleInput(['enabled' => false])))->toBe([]);
});

it('AC-14: accepts a 500th enabled rule, and a change to a rule that is already enabled', function (): void {
    insertEnabledRules(499);
    $enabled = Rule::create(ruleInput());

    expect(refusedFields(['max_attempts' => 5], $enabled))->toBe([])
        ->and(refusedFields(ruleInput(['enabled' => false])))->toBe([]);
});
