# Contributing

Thanks for helping. Rate-limiting bugs are quiet: an off-by-one limit or a race condition crashes nothing, so nobody notices until production. The workflow below exists to catch those bugs early.

## Before you start

- Read the [ADR index](docs/adr/README.md). ADRs record why the package is shaped the way it is.
- For anything bigger than a one-sentence change, open an issue first so we can agree on the behavior.
- Security problems go through [SECURITY.md](SECURITY.md), never through public issues.

## Workflow ([ADR-0003](docs/adr/0003-development-method.md))

1. **Spec.** Features start with a spec in `docs/specs/` (use `TEMPLATE.md`). Acceptance criteria use concrete numbers.
2. **Failing tests.** Write the Pest tests first and commit them on their own (`test: …`). Test names start with the criterion id: `it('AC-3: …')`.
3. **Implementation.** Make the tests pass without editing them. If a test looks wrong, say so in the PR instead of changing it quietly.
4. **Checks.** All of these must pass:

   ```bash
   composer test
   composer analyse
   composer format:check
   composer refactor:check
   ```

Tiny changes can skip the spec. Bug fixes always start with a failing regression test.

If a spec would break an ADR, write a new ADR that supersedes the old one first.

## Local setup

The test suite runs in Docker, so you do not need PHP 8.3 installed:

```bash
docker compose run --rm php composer update
docker compose run --rm php composer test
```

Driver tests need the backing services:

```bash
docker compose up -d
docker compose run --rm php composer test:drivers
docker compose down -v
```

`docker compose run --rm php scripts/verify-install.sh 13` installs the package into a fresh Laravel app and checks that it boots.

## Style

- PHP 8.3 syntax, `declare(strict_types=1)` everywhere, final classes by default, readonly value objects, and backed enums instead of string constants.
- `tests/Arch` enforces several hard rules: no database access on the request path, no direct clock reads in the engine, no insecure functions. Never weaken an arch test to make code pass.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `test:`, `docs:`, `refactor:`, `ci:`, `chore:`.
- User-facing changes get an entry in [CHANGELOG.md](CHANGELOG.md).

## Public API

These are covered by Semantic Versioning from 1.0 ([ADR-0017](docs/adr/0017-versioning-and-release.md)):
- config keys
- contracts
- events
- Artisan commands
- HTTP headers
- the facade
- the database schema

A PR that changes any of them must say so, and a breaking change needs an upgrade note. Published migrations are never edited. Schema changes ship as new migrations.

## Licensing of contributions

By submitting a contribution, you agree that it is licensed under this project's [MIT License](LICENSE) (inbound = outbound). You also confirm that you have the right to submit it: it is your own work, or it comes from a source whose license is compatible with MIT and is credited in the PR. Do not copy code from projects with incompatible licenses.

## Code of conduct

This project follows its [Code of Conduct](CODE_OF_CONDUCT.md). By taking part, you agree to follow it.
