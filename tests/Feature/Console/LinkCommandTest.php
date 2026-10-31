<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-034: dynamic-throttle:link makes, lists and ends dashboard links (ADR-0031),
| AC-1 to AC-10. Links are on, APP_URL is https://example.com, and the clock stands
| at 2027-01-01 00:00:00 UTC.
*/

beforeEach(function (): void {
    RuleSchema::migrate();
    config()->set('dynamic-throttle.dashboard.links', true);
    config()->set('app.url', 'https://example.com');
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
});

/**
 * Runs the command without a terminal, as a deploy script would, and returns its exit code and output.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function linkCommand(array $options): array
{
    $code = Artisan::call('dynamic-throttle:link', [...$options, '--no-interaction' => true]);

    return [$code, Artisan::output()];
}

/**
 * The token at the end of the link the output prints.
 */
function linkTokenIn(string $output): string
{
    preg_match('~/link#([^\s]+)~', $output, $match);

    return $match[1] ?? '';
}

/**
 * @return list<array<string, mixed>>
 */
function linkRows(): array
{
    return DB::table('dynamic_throttle_links')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
}

function linkCount(): int
{
    return DB::table('dynamic_throttle_links')->count();
}

it('AC-1: makes a link that only views, for 60 minutes, and prints it once', function (): void {
    [$code, $output] = linkCommand(['--name' => 'alice, incident 42']);
    $row = linkRows()[0];
    $token = linkTokenIn($output);

    expect($code)->toBe(0)
        ->and($output)->toContain("Link {$row['id']} for \"alice, incident 42\": view only, until 2027-01-01 01:00:00 UTC.")
        ->and($output)->toContain("https://example.com/dynamic-throttle/link#{$token}")
        ->and($output)->toContain('It works once, in one browser. Send it privately: until it is used, anyone who has it can open the dashboard.')
        ->and($output)->toContain("End it early with: php artisan dynamic-throttle:link --revoke={$row['id']}")
        ->and($token)->toMatch('/\A[A-Za-z0-9_-]{43}\z/')
        // strlen, not toHaveLength: Pest counts a text's characters, and random bytes are no UTF-8 text.
        ->and(strlen((string) base64_decode(strtr($token, '-_', '+/'), true)))->toBe(32);
});

it('AC-1: stores only the token\'s SHA-256 hash, with who the link is for, what it allows, when it ends and who made it', function (): void {
    [, $output] = linkCommand(['--name' => 'alice, incident 42']);
    $token = linkTokenIn($output);
    $row = linkRows()[0];

    expect([
        'name' => $row['name'],
        'token_hash' => $row['token_hash'],
        'can_manage' => (bool) $row['can_manage'],
        'created_by' => $row['created_by'],
        'expires_at' => $row['expires_at'],
        'used_at' => $row['used_at'],
        'revoked_at' => $row['revoked_at'],
        'created_at' => $row['created_at'],
    ])->toBe([
        'name' => 'alice, incident 42',
        'token_hash' => hash('sha256', $token),
        'can_manage' => false,
        'created_by' => Settings::cliActor(),
        'expires_at' => '2027-01-01 01:00:00',
        'used_at' => null,
        'revoked_at' => null,
        'created_at' => '2027-01-01 00:00:00',
    ])->and(json_encode($row))->not->toContain($token);
});

it('AC-1: writes one link.create entry, which holds neither the token nor its hash', function (): void {
    [, $output] = linkCommand(['--name' => 'alice, incident 42']);
    $token = linkTokenIn($output);
    $id = linkRows()[0]['id'];

    expect(Settings::audit())->toBe([[
        'actor' => Settings::cliActor(),
        'action' => 'link.create',
        'target' => "link:{$id}",
        'before' => null,
        'after' => ['name' => 'alice, incident 42', 'can_manage' => false, 'expires_at' => '2027-01-01T01:00:00+00:00', 'created_by' => Settings::cliActor()],
        'ip' => null,
        'user_agent' => null,
    ]])->and(json_encode(Settings::audit()))->not->toContain($token)->not->toContain(hash('sha256', $token));
});

it('AC-1: makes a new token for each link', function (): void {
    [, $first] = linkCommand(['--name' => 'alice']);
    [, $second] = linkCommand(['--name' => 'alice']);

    expect(linkTokenIn($first))->not->toBe(linkTokenIn($second))
        ->and(array_column(linkRows(), 'name'))->toBe(['alice', 'alice']);
});

it('AC-1: builds the link from APP_URL and the dashboard\'s path, on dashboard.domain when it is set', function (array $config, string $link): void {
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    [$code, $output] = linkCommand(['--name' => 'alice']);

    expect($code)->toBe(0)->and($output)->toContain($link.linkTokenIn($output));
})->with([
    'the default path' => [[], 'https://example.com/dynamic-throttle/link#'],
    'another path' => [['dynamic-throttle.dashboard.path' => 'ops/throttle'], 'https://example.com/ops/throttle/link#'],
    'APP_URL with a slash at the end' => [['app.url' => 'https://example.com/'], 'https://example.com/dynamic-throttle/link#'],
    'an app in a folder' => [['app.url' => 'https://example.com/shop'], 'https://example.com/shop/dynamic-throttle/link#'],
    'dashboard.domain' => [['dynamic-throttle.dashboard.domain' => 'admin.example.com'], 'https://admin.example.com/dynamic-throttle/link#'],
]);

it('AC-2: --for sets how long the link works, up to a day', function (string $for, string $until): void {
    [$code, $output] = linkCommand(['--name' => 'alice', '--for' => $for]);

    expect($code)->toBe(0)
        ->and(linkRows()[0]['expires_at'])->toBe($until)
        ->and($output)->toContain("until {$until} UTC.");
})->with([
    '90s' => ['90s', '2027-01-01 00:01:30'],
    '30m' => ['30m', '2027-01-01 00:30:00'],
    '2h' => ['2h', '2027-01-01 02:00:00'],
    '1d' => ['1d', '2027-01-02 00:00:00'],
]);

it('AC-2: refuses a --for longer than a day, and writes nothing', function (string $for): void {
    [$code, $output] = linkCommand(['--name' => 'alice', '--for' => $for]);

    expect($code)->toBe(1)
        ->and($output)->toContain("--for can be at most 1d for a link, got \"{$for}\".")
        ->and(linkCount())->toBe(0)
        ->and(Settings::audit())->toBe([]);
})->with(['25h', '2d']);

it('AC-2: refuses a --for the other commands refuse, with their message, and writes nothing', function (string $for): void {
    [$code, $output] = linkCommand(['--name' => 'alice', '--for' => $for]);

    expect($code)->toBe(1)
        ->and($output)->toContain("--for must be a whole number and a unit, s, m, h or d, such as 30m, from 1 second to 365 days, got \"{$for}\".")
        ->and(linkCount())->toBe(0)
        ->and(Settings::audit())->toBe([]);
})->with(['0m', 'soon']);

it('AC-3: --manage makes a link that can manage, and says so', function (): void {
    [$code, $output] = linkCommand(['--name' => 'alice', '--manage' => true]);
    $row = linkRows()[0];

    expect($code)->toBe(0)
        ->and($output)->toContain("Link {$row['id']} for \"alice\": can view and change rules, exemptions and settings, until 2027-01-01 01:00:00 UTC.")
        ->and((bool) $row['can_manage'])->toBeTrue()
        ->and(Settings::audit()[0]['after']['can_manage'])->toBeTrue();
});

it('AC-3: refuses --manage in production without a terminal and without --force, and writes nothing', function (): void {
    $this->app['env'] = 'production';

    [$code, $output] = linkCommand(['--name' => 'alice', '--manage' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('The link lets whoever holds it change rules, exemptions and settings until 2027-01-01 01:00:00 UTC: in production, pass --force to confirm without a terminal.')
        ->and(linkCount())->toBe(0)
        ->and(Settings::audit())->toBe([]);
});

it('AC-3: makes a --manage link in production with --force, without asking', function (): void {
    $this->app['env'] = 'production';

    [$code] = linkCommand(['--name' => 'alice', '--manage' => true, '--force' => true]);

    expect($code)->toBe(0)->and(linkCount())->toBe(1);
});

it('AC-3: asks in production before making a --manage link, and writes nothing on no', function (string $answer, int $code, int $links): void {
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:link', ['--name' => 'alice', '--manage' => true])
        ->expectsConfirmation('Are you sure you want to run this command?', $answer)
        ->assertExitCode($code)
        ->run();

    expect(linkCount())->toBe($links);
})->with([
    'yes' => ['yes', 0, 1],
    'no' => ['no', 1, 0],
]);

it('AC-3: asks nothing for a --manage link outside production, nor for a view link in production', function (): void {
    [$local] = linkCommand(['--name' => 'alice', '--manage' => true]);
    $this->app['env'] = 'production';
    [$view] = linkCommand(['--name' => 'bob']);

    expect([$local, $view])->toBe([0, 0])->and(linkCount())->toBe(2);
});

it('AC-4: refuses a missing or empty --name, and writes nothing', function (array $options): void {
    [$code, $output] = linkCommand($options);

    expect($code)->toBe(1)
        ->and($output)->toContain('--name is required: who the link is for, such as "alice, incident 42".')
        ->and(linkCount())->toBe(0);
})->with([
    'no option at all' => [[]],
    'only --for' => [['--for' => '30m']],
    'empty' => [['--name' => '']],
    'only spaces' => [['--name' => '   ']],
]);

it('AC-4: refuses a --name of more than 100 characters, or with a control or invisible format character, and writes nothing', function (string $name): void {
    [$code] = linkCommand(['--name' => $name]);

    expect($code)->toBe(1)->and(linkCount())->toBe(0)->and(Settings::audit())->toBe([]);
})->with([
    '101 characters' => [str_repeat('a', 101)],
    'a terminal escape' => ["alice\e[31m"],
    'a right-to-left override' => ["alice\u{202E}"],
    'a zero-width space' => ["ali\u{200B}ce"],
    'not UTF-8' => ["alice\xFF"],
]);

it('AC-4: accepts a --name of 100 characters, counted as characters', function (): void {
    [$code] = linkCommand(['--name' => str_repeat('ä', 100)]);

    expect($code)->toBe(0)->and(linkRows()[0]['name'])->toBe(str_repeat('ä', 100));
});

it('AC-5: warns when the link starts with http:// in production, and still makes it', function (): void {
    config()->set('app.url', 'http://example.com');
    $this->app['env'] = 'production';

    [$code, $output] = linkCommand(['--name' => 'alice']);

    expect($code)->toBe(0)
        ->and($output)->toContain('The link starts with http://, so anyone on the network can read it: set APP_URL to an https:// address.')
        ->and(linkCount())->toBe(1);
});

it('AC-5: does not warn about https://, nor about http:// in the local environment', function (string $url, string $environment): void {
    config()->set('app.url', $url);
    $this->app['env'] = $environment;

    [, $output] = linkCommand(['--name' => 'alice']);

    expect($output)->not->toContain('The link starts with http://');
})->with([
    'https in production' => ['https://example.com', 'production'],
    'http locally' => ['http://localhost', 'local'],
]);

it('AC-6: lists the links that have not ended, newest first, without their tokens', function (): void {
    [, $alice] = linkCommand(['--name' => 'alice']);
    $this->travel(5)->minutes();
    linkCommand(['--name' => 'bob', '--manage' => true]);
    linkCommand(['--name' => 'gone', '--for' => '1m']);
    $this->travel(5)->minutes();
    linkCommand(['--name' => 'revoked']);
    linkCommand(['--revoke' => '4']);
    DB::table('dynamic_throttle_links')->where('name', 'bob')->update(['used_at' => '2027-01-01 00:06:00']);

    [$code, $output] = linkCommand(['--list' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Id')->toContain('For')->toContain('Can')->toContain('Used')->toContain('Until')->toContain('Created by')
        ->and($output)->toContain('alice')->toContain('bob')
        ->and($output)->not->toContain('gone')->not->toContain('revoked')
        ->and(strpos($output, 'bob'))->toBeLessThan(strpos($output, 'alice'))
        ->and($output)->toContain('view and manage')->toContain('2027-01-01 00:06:00 UTC')->toContain('not yet')
        ->and($output)->toContain('2027-01-01 01:00:00 UTC')->toContain(Settings::cliActor())
        ->and($output)->not->toContain(linkTokenIn($alice))->not->toContain(hash('sha256', linkTokenIn($alice)));
});

it('AC-6: says when no link is active', function (): void {
    linkCommand(['--name' => 'gone', '--for' => '1m']);
    $this->travel(2)->minutes();

    [$code, $output] = linkCommand(['--list' => true]);

    expect($code)->toBe(0)->and($output)->toContain('No link is active.');
});

it('AC-7: ends a link, used or not, with one link.revoke entry', function (): void {
    linkCommand(['--name' => 'alice']);
    linkCommand(['--name' => 'bob']);
    DB::table('dynamic_throttle_links')->where('name', 'bob')->update(['used_at' => '2027-01-01 00:00:00']);
    $this->travel(10)->minutes();

    [$alice, $aliceOutput] = linkCommand(['--revoke' => '1']);
    [$bob] = linkCommand(['--revoke' => '2']);
    $entry = fn (int $id): array => [
        'actor' => Settings::cliActor(),
        'action' => 'link.revoke',
        'target' => "link:{$id}",
        'before' => ['revoked_at' => null],
        'after' => ['revoked_at' => '2027-01-01T00:10:00+00:00'],
        'ip' => null,
        'user_agent' => null,
    ];

    expect([$alice, $bob])->toBe([0, 0])
        ->and($aliceOutput)->toContain('Link 1 for "alice" has ended.')
        ->and(array_column(linkRows(), 'revoked_at'))->toBe(['2027-01-01 00:10:00', '2027-01-01 00:10:00'])
        ->and(array_slice(Settings::audit(), 2))->toBe([$entry(1), $entry(2)]);
});

it('AC-7: says a link has already ended, revoked or expired, and writes nothing', function (): void {
    linkCommand(['--name' => 'alice']);
    linkCommand(['--name' => 'short', '--for' => '1m']);
    linkCommand(['--revoke' => '1']);
    $this->travel(2)->minutes();
    $entries = count(Settings::audit());

    [$again, $againOutput] = linkCommand(['--revoke' => '1']);
    [$expired, $expiredOutput] = linkCommand(['--revoke' => '2']);

    expect([$again, $expired])->toBe([0, 0])
        ->and($againOutput)->toContain('Link 1 has already ended.')
        ->and($expiredOutput)->toContain('Link 2 has already ended.')
        ->and(count(Settings::audit()))->toBe($entries);
});

it('AC-7: refuses an id no link has, and one that is not a whole number', function (string $id, string $message): void {
    linkCommand(['--name' => 'alice']);

    [$code, $output] = linkCommand(['--revoke' => $id]);

    expect($code)->toBe(1)
        ->and($output)->toContain($message)
        ->and(linkRows()[0]['revoked_at'])->toBeNull();
})->with([
    'unknown' => ['99', 'No link has the id 99.'],
    'not a number' => ['abc', '--revoke must be a link\'s id, a whole number, got "abc".'],
    'zero' => ['0', '--revoke must be a link\'s id, a whole number, got "0".'],
]);

it('AC-8: ends every link that has not ended, with one link.revoke entry each, and says how many', function (int $active, string $message): void {
    for ($i = 1; $i <= $active; $i++) {
        linkCommand(['--name' => "link {$i}"]);
    }
    linkCommand(['--name' => 'gone', '--for' => '1m']);
    $this->travel(2)->minutes();
    $entries = count(Settings::audit());

    [$code, $output] = linkCommand(['--revoke-all' => true]);
    $revoked = array_slice(Settings::audit(), $entries);

    expect($code)->toBe(0)
        ->and($output)->toContain($message)
        ->and(array_column($revoked, 'action'))->toBe(array_fill(0, $active, 'link.revoke'))
        ->and(array_column($revoked, 'target'))->toBe(array_map(fn (int $id): string => "link:{$id}", range(1, $active)))
        ->and(DB::table('dynamic_throttle_links')->whereNull('revoked_at')->pluck('name')->all())->toBe(['gone']);
})->with([
    'three' => [3, 'Ended 3 links.'],
    'one' => [1, 'Ended 1 link.'],
]);

it('AC-8: says when no link is active, and writes nothing', function (): void {
    linkCommand(['--name' => 'gone', '--for' => '1m']);
    $this->travel(2)->minutes();

    [$code, $output] = linkCommand(['--revoke-all' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('No link is active.')
        ->and(array_column(Settings::audit(), 'action'))->toBe(['link.create']);
});

it('AC-8: ends no link when an audit entry cannot be written', function (): void {
    linkCommand(['--name' => 'alice']);
    linkCommand(['--name' => 'bob']);
    DB::statement("CREATE TRIGGER audit_down BEFORE INSERT ON dynamic_throttle_audit_log BEGIN SELECT RAISE(ABORT, 'The audit log is down.'); END");

    [$code, $output] = linkCommand(['--revoke-all' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Could not save the change:')
        ->and(array_column(linkRows(), 'revoked_at'))->toBe([null, null]);
});

it('AC-1: makes no link when its audit entry cannot be written', function (): void {
    DB::statement("CREATE TRIGGER audit_down BEFORE INSERT ON dynamic_throttle_audit_log BEGIN SELECT RAISE(ABORT, 'The audit log is down.'); END");

    [$code, $output] = linkCommand(['--name' => 'alice']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Could not save the change:')
        ->and($output)->not->toContain('/link#')
        ->and(linkCount())->toBe(0);
});

it('AC-9: refuses every mode while links are off, the default, without reading the table', function (array $options): void {
    config()->set('dynamic-throttle.dashboard.links');
    Schema::drop('dynamic_throttle_links');

    [$code, $output] = linkCommand($options);

    expect($code)->toBe(1)->and($output)->toContain('Links are off: set DYNAMIC_THROTTLE_DASHBOARD_LINKS=true (dynamic-throttle.dashboard.links) to use them.');
})->with([
    'make' => [['--name' => 'alice']],
    'list' => [['--list' => true]],
    'revoke' => [['--revoke' => '1']],
    'revoke all' => [['--revoke-all' => true]],
]);

it('AC-9: takes links as off for any value but true', function (mixed $value): void {
    config()->set('dynamic-throttle.dashboard.links', $value);

    [$code, $output] = linkCommand(['--name' => 'alice']);

    expect($code)->toBe(1)->and($output)->toContain('Links are off:')->and(linkCount())->toBe(0);
})->with([false, 'false', 0, '', 'no']);

it('AC-9: refuses every mode while the dashboard is off', function (array $options): void {
    config()->set('dynamic-throttle.dashboard.enabled', false);

    [$code, $output] = linkCommand($options);

    expect($code)->toBe(1)
        ->and($output)->toContain('The dashboard is off (dynamic-throttle.dashboard.enabled), so a link would open nothing.')
        ->and(linkCount())->toBe(0);
})->with([
    'make' => [['--name' => 'alice']],
    'list' => [['--list' => true]],
    'revoke' => [['--revoke' => '1']],
    'revoke all' => [['--revoke-all' => true]],
]);

it('AC-9: refuses every mode while links are on but their table is missing', function (array $options): void {
    Schema::drop('dynamic_throttle_links');

    [$code, $output] = linkCommand($options);

    expect($code)->toBe(1)->and($output)->toContain('The dynamic_throttle_links table does not exist.');
})->with([
    'make' => [['--name' => 'alice']],
    'list' => [['--list' => true]],
    'revoke' => [['--revoke' => '1']],
    'revoke all' => [['--revoke-all' => true]],
]);

it('AC-10: refuses two modes at once, and writes nothing', function (array $options): void {
    linkCommand(['--name' => 'alice']);

    [$code, $output] = linkCommand($options);

    expect($code)->toBe(1)
        ->and($output)->toContain('Pass one of --name, --list, --revoke or --revoke-all.')
        ->and(linkCount())->toBe(1)
        ->and(linkRows()[0]['revoked_at'])->toBeNull();
})->with([
    '--revoke and --name' => [['--revoke' => '1', '--name' => 'x']],
    '--list and --revoke-all' => [['--list' => true, '--revoke-all' => true]],
    '--name and --list' => [['--name' => 'x', '--list' => true]],
    '--revoke and --revoke-all' => [['--revoke' => '1', '--revoke-all' => true]],
]);
