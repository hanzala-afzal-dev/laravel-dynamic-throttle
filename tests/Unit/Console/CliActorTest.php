<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Console\CliActor;

covers(CliActor::class);

/*
| SPEC-013 AC-23: the CLI's actor in the audit log is cli:<system user>
| (ADR-0015). Found by the review: through sudo, the system user is root for
| everyone, so the actor also names the user who ran sudo.
*/

afterEach(function (): void {
    putenv('SUDO_USER');
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
