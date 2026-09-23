# ADR-0019: Declare only the Laravel versions CI tests

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 0
- Amends: [ADR-0002](0002-supported-platforms.md) (the Laravel constraint)
- Related: ADR-0016

## Context

- ADR-0002 set the Laravel constraint to `^12.0 || ^13.0`.
- CI's `prefer-lowest` jobs are meant to test the oldest versions users can install. In practice they install **Laravel 12.61.1 and 13.12.0**, on PHP 8.3 and on 8.5, because the test tools cannot run on older versions:
  - Orchestra Testbench 10.8 conflicts with `laravel/framework` below 12.40.
  - Other Testbench, Pest and Larastan releases raise the floor further.
- So `composer.json` promised Laravel 12.0 to 12.61.0 and 13.0 to 13.11, which no test ever ran. A user on those versions could install the package, then hit a missing framework API at runtime.
- The maintainer asked for proper requirement checks before installation.

## Decision

1. `composer.json` requires `laravel/framework: ^12.61.1 || ^13.12.0`: the versions CI's `prefer-lowest` jobs actually install.
2. CI narrows Laravel with `composer update --with "laravel/framework:^N.0"`. The `--with` flag intersects with the constraint in `composer.json`, so the lowest job installs the declared floor, not an older version.
3. `scripts/check-laravel-floor.php` runs in every `prefer-lowest` job. It fails when the lowest installed version differs from the declared floor, with a message that says which floor to declare.
4. When the test tools raise the floor, raise it in `composer.json` and the README in the same pull request. Raising the floor within a supported Laravel major is allowed in a minor release and is called out in the changelog.

The rest of ADR-0002 (PHP `^8.3`, Laravel 12 and 13, the support policy) is unchanged.

## Reasoning

- A version constraint is a promise. Every version the promise covers should be one that CI has run.
- Only the latest release of a Laravel major gets security fixes, so asking users to update within their major costs little. It is also what they should do anyway.
- The check turns "the floor drifted" from a silent gap into a failing job.

## Alternatives considered

- **Keep `^12.0 || ^13.0`.** A wider install range, but most of it untested. Rejected.
- **Pin older test tools so `prefer-lowest` reaches Laravel 12.0.** That needs old Testbench, Pest and PHPUnit majors that do not support PHP 8.5, and adds a second toolchain to maintain. Rejected.

## Fallback

- If users on older Laravel minors ask for support, check whether an older test toolchain can cover that range in a separate CI job, and lower the floor only if it can.
- **Revisit when:** a new Laravel major is added (ADR-0002), and whenever the floor check fails.

## Consequences

- Good: every Laravel version that Composer allows has been tested.
- Bad: users on older 12.x or 13.x minors must update Laravel before installing, and the floor moves up over time.

## Verification

- The `prefer-lowest` CI jobs run `scripts/check-laravel-floor.php` and fail if the installed floor differs from the declared one.
- Verified locally on 2026-09-23: PHP 8.3 and 8.5 with `--prefer-lowest` install exactly 12.61.1 and 13.12.0, and the suite passes on both.
