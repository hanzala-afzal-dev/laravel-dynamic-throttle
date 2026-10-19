<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Console\CliActor;

covers(CliActor::class);

/*
| SPEC-013 AC-23: the CLI's actor in the audit log is cli:<system user>
| (ADR-0015). Found by the review: through sudo, the system user is root for
| everyone, so the actor also names the user who ran sudo.
*/

beforeEach(function (): void {
    $this->environment = ['USER' => getenv('USER'), 'USERNAME' => getenv('USERNAME')];
});

afterEach(function (): void {
    putenv('SUDO_USER');

    foreach ($this->environment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }
});

function systemUser(): string
{
    return function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] ?? '' : (string) getenv('USER');
}

it('AC-23: names the system user that runs the command', function (): void {
    putenv('SUDO_USER');

    expect(CliActor::name())->toBe('cli:'.systemUser());
});

it('names the user who ran the command through sudo', function (): void {
    putenv('SUDO_USER=alice');

    expect(CliActor::name())->toBe('cli:'.systemUser().' (sudo by alice)');
});

it('names no sudo user when it is the system user itself', function (): void {
    putenv('SUDO_USER='.systemUser());

    expect(CliActor::name())->toBe('cli:'.systemUser());
});

/*
| Issue #96: without the posix extension, Windows names its user in USERNAME,
| not USER, so every CLI change was logged as cli:unknown.
*/

it('names the user from USER without the posix extension', function (): void {
    putenv('USER=hanzala');
    putenv('USERNAME');

    expect(CliActor::named(null))->toBe('cli:hanzala');
});

it('names the user from USERNAME on Windows, which has no USER', function (): void {
    putenv('USER');
    putenv('USERNAME=Hanzala');

    expect(CliActor::named(null))->toBe('cli:Hanzala');
});

it('prefers USER to USERNAME', function (): void {
    putenv('USER=hanzala');
    putenv('USERNAME=Administrator');

    expect(CliActor::named(null))->toBe('cli:hanzala');
});

it('says unknown when neither names a user', function (): void {
    putenv('USER');
    putenv('USERNAME=');

    expect(CliActor::named(null))->toBe('cli:unknown');
});
