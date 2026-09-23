# Security policy

This package sits on the request path of the apps that install it, and its dashboard controls login protection and API limits. Security reports are taken seriously.

## Supported versions

| Version | Security fixes |
|---|---|
| Latest 0.x release | Yes |
| Older 0.x releases | No, please upgrade |

Before 1.0, only the latest release gets fixes. From 1.0 on, this table will list the supported majors.

## Reporting a vulnerability

**Do not open a public issue, discussion or pull request for a security problem.**

Report it privately through GitHub's private vulnerability reporting:

**<https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle/security/advisories/new>**

Please include:

- the affected version or commit
- a description of the problem and its impact
- steps to reproduce, ideally a minimal test or request sequence
- any suggested fix

## What to expect

This is a solo-maintained open-source project, so these are best-effort targets, not guarantees:

- acknowledgement within 7 days
- an assessment and a plan within 14 days
- a fix released as soon as it is ready, followed by a published GitHub security advisory
- credit in the advisory, if you want it

Please give us a reasonable time to release a fix before you disclose the problem publicly.

## Scope

In scope, for example:

- bypassing an enforced limit in a way the documentation does not describe
- reaching or changing the dashboard or its JSON API without the required gate
- cross-site scripting, CSRF or clickjacking in the dashboard
- raw tokens, emails or other identifiers leaking into cache keys, metrics or logs
- changing or deleting audit log entries

Documented limitations, not vulnerabilities:

- requests are allowed while the counter storage is unavailable, unless a rule is set to fail closed ([ADR-0010](docs/adr/0010-failure-policy.md))
- clients can set any value for the `header` scope, which is meant only for headers set by a trusted gateway ([ADR-0009](docs/adr/0009-scopes-and-identity.md))
- client IPs are only as correct as the host app's trusted-proxy settings
- volumetric attacks are the job of edge rate limiting ([ADR-0001](docs/adr/0001-scope-and-positioning.md))
