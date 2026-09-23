## What and why

<!-- One or two sentences. Link the spec (docs/specs/…) or the issue. -->

## Checklist

- [ ] Linked spec or issue (or: this is a one-sentence change)
- [ ] Tests were written first and committed separately from the implementation (ADR-0003)
- [ ] `composer test`, `composer analyse`, `composer format:check` and `composer refactor:check` pass
- [ ] `CHANGELOG.md` has an entry under **Unreleased** (for user-facing changes)

## Does this change the public API? (ADR-0017)

- [ ] No
- [ ] Yes. What changes: config keys / contracts / events / Artisan commands / HTTP headers / facade / database schema
  - [ ] Breaking changes have an upgrade note
  - [ ] Schema changes ship as a **new** migration (published migrations are never edited)
