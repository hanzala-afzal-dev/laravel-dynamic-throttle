# SPEC-029: The runbook

- Status: Done
- ADRs: ADR-0007, ADR-0010, ADR-0011, ADR-0015
- Roadmap phase: 3

## Problem

Phase 3's exit needs `docs/runbook.md`: what to do when "Redis is down", "the manifest was evicted", "a bad rule blocks /api" and "an emergency change was left on", each scenario with a test (the roadmap). The behavior exists, spread over SPEC-006, SPEC-012, SPEC-013, SPEC-014 and SPEC-016, but someone in an incident needs the steps in one place, and needs them to be true. One scenario also has a gap: nothing warns about an emergency setting that never ends.

## Behavior

- **`docs/runbook.md`** has one section per scenario, each with the same four parts:
  - **Symptoms:** what you see, in your app, your error tracker and the package's commands.
  - **Check:** the commands that confirm it, and what they print.
  - **Act:** the steps, in order.
  - **After:** what returns by itself, and what to check once it is over.

  It links each section to the test that follows its steps, and the README and the guide link to it.
- **Each scenario has a test** in `tests/Feature/Runbook/`, one file per scenario. The test follows the section's steps and asserts what the section says you will see.
- **A new health check, `emergency`** (decision 2), for the fourth scenario:
  - it warns about an emergency setting that servers apply with no end time, or with one more than 24 hours away;
  - it is ok, naming the end time, while a setting applies that ends within 24 hours, and ok with no note when none applies;
  - it reads the manifest that servers read, as `status` does.

## Acceptance criteria

- **AC-1 (Redis is down)** Given an enforcing fail-open rule, a fail-closed rule on another route, and one cache store that holds the manifest and the counters, as Redis usually does, and goes down:
  - a request to the fail-open rule's route is let through without rate-limit headers. The counters' failure is reported once per `failure.cooldown_seconds`, and the manifest's once per 30 seconds, however many requests come;
  - a request to the fail-closed rule's route gets a 503 with `Retry-After`, from the rules the process read before the outage;
  - a process that starts during the outage applies no rule, not even the fail-closed one;
  - `dynamic-throttle:status` still exits 0, prints the driver, and says the manifest cannot be read;
  - when only the counter store is down, `bypass on --for=30m --force` lets every request through. When the manifest's store is down too, `bypass` exits 1, and `DYNAMIC_THROTTLE_ENABLED=false` lets every request through;
  - after the store recovers and the cooldown ends, the next request is counted again and gets its headers;
  - on PHP-FPM with APCu, the manifest's failure is reported at most once per 30 seconds per server (a gap, below).
- **AC-2 (the manifest was evicted)** Given a compiled manifest that the cache loses:
  - a process that had read it keeps applying its rules, and rebuilds it after a response;
  - a process that had not applies no rule to the first request, rebuilds the manifest after that request's response, and limits the next request;
  - the missing manifest is reported, once;
  - before the rebuild, `dynamic-throttle:status` prints "missing: run php artisan dynamic-throttle:compile", and `dynamic-throttle:health` errs on `manifest` and exits 1;
  - `dynamic-throttle:compile` restores it at once, and then `health`'s `manifest` check is ok;
  - with the database down, the rebuild fails and is reported, the requests meanwhile apply no rule, and the rebuild is tried again at most once per 30 seconds. `compile` prints "Could not compile the manifest" and exits 1, and once the database is back, it restores the manifest.
- **AC-3 (a bad rule blocks /api)** Given an enforcing rule that answers 429 to every request under `/api`, in production:
  - each 429 dispatches `RateLimitExceeded`, naming the rule;
  - `dynamic-throttle:explain GET /api/orders` names the rule and its 429;
  - `dynamic-throttle:list` shows its id, and `dynamic-throttle:disable-rule <id> --force` stops the 429s for the requests after it, and writes one audit entry;
  - after the rule is fixed with the model, `dynamic-throttle:enable-rule <id>` applies the fixed rule, `explain` shows it and "Decision: allowed", and the audit log holds `rule.disable` and `rule.enable`;
  - `dynamic-throttle:bypass on --for=30m --force`, the step for when the rule cannot be found quickly, lets every request through, writes `settings.bypass`, and `status` shows its end time.
- **AC-4 (an emergency change was left on)**
  - Given `bypass on` without `--for`, an hour later requests are limited again, with no command run, and `status` shows the setting's end time as passed.
  - Given `bypass on --forever`, `status` shows "with no end time", `health` warns on `emergency` and exits 1 with `--strict`, and `bypass off` ends both.
- **AC-5 (the `emergency` check)**
  - With no setting applied, it is ok.
  - With bypass or a scale factor that ends within 24 hours, it is ok and names the setting and its end time.
  - With one that ends later, or never, it warns, names the setting and its end time or "no end time", and says how to end it (`bypass off`, `scale off`).
  - Without a manifest, it is skipped, since the `manifest` check errs then.
  - It changes nothing (SPEC-016 AC-21).
- **AC-6** The runbook's four sections link their tests, and the README and the guide link the runbook.

## Interfaces and files

- `docs/runbook.md`.
- `tests/Feature/Runbook/RedisDownTest.php`, `ManifestEvictedTest.php`, `BadRuleTest.php` and `EmergencyLeftOnTest.php`.
- `tests/Support/OutageCache.php`: a cache store that goes down and comes back with its data.
- `src/Support/Health/Checks/EmergencyCheck.php`, registered in the health checks after `manifest`. The check id `emergency` joins the command's public ids (SPEC-016 decision 2).
- `README.md` and `docs/guide/README.md`: a link to the runbook, and the new check in the README's command table only if it lists checks.

## Out of scope

- New behavior in the scenarios themselves: the runbook documents what SPEC-006, SPEC-012 and SPEC-013 built. A scenario whose test shows a gap is fixed test-first in the PR of that scenario, and recorded here.
- Alerts. The runbook names the events to listen to (`StorageUnavailable`, `RateLimitExceeded`), and leaves the alerting to the app.
- The dashboard's pages for incidents (Phase 5).

## Edge cases and failure modes

- **Redis is down and holds the manifest too,** the usual setup: servers keep the manifest they last read (ADR-0007's L0 and L1), so rules still apply. A process that starts during the outage has none, and applies no rule until Redis returns (ADR-0010 point 3). The runbook says so.
- **Redis returns empty:** the counters start from zero, so every client gets a fresh window, and the manifest is rebuilt as in AC-2.
- **The cooldown** is per process, or per server with APCu, so different servers retry the store at different times.
- **`--force`** is in the runbook's commands, because an incident's terminal may not answer the production confirmation. The runbook says what each confirmation protects against.
- **`emergency` near the boundary:** a setting that ends exactly 24 hours ahead is ok.

## Gaps found

- **The manifest's failure report on PHP-FPM** (part 2). SPEC-006 AC-17 reports an unreadable manifest at most once per 30 seconds, but kept the time of the last report in the process, which PHP-FPM empties on every request. A Redis outage reported it about once per `manifest.refresh_seconds` per server with APCu, and on every request with no manifest to fall back on. The time is now kept in APCu too, as the counter store's reports are (SPEC-012). Tested by `tests/Apcu/ApcuManifestReportTest.php`.

## End-to-end verification

- `composer test -- --filter=Runbook` and `composer test -- --filter=EmergencyCheck`
- `composer test`, `composer analyse`
- Following each runbook section by hand in the Workbench app, with Redis stopped for the first.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **One section per scenario, in four parts:** symptoms, check, act, after. Each links the test that follows its steps. Rejected: one long page of prose, which is hard to follow during an incident.
2. **A new health check, `emergency`, for settings left on.** The only signal today is `status`, which someone has to run. The check warns when a setting applies with no end time, or ends more than 24 hours ahead, so `health --strict` in a deploy script catches a forgotten `--forever`. A setting that ends within a day is ok, with its end time named, so a deploy during an incident does not fail. Rejected: warning about every active setting, which would fail a strict deploy in the middle of an incident; and a time limit on `--forever`, which would change SPEC-013's commands.
3. **The tests live in `tests/Feature/Runbook/`,** one per scenario, named after its section. They repeat assertions that the specs' own tests make, on purpose: they prove the runbook's steps, not the features.
4. **Five stacked PRs, one per scenario after the spec** (below), so each stays small and is reviewed on its own (ADR-0027).

## PRs

Stacked on each other, each green on its own (ADR-0027). The scenarios' tests document behavior that exists, so they pass at once, except where a test finds a gap. The `emergency` check is new, so its tests are committed red before it.

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-3/spec-029-1` | This spec, and its line in the roadmap | Decisions |
| 2 | `phase-3/spec-029-2` | `docs/runbook.md` with its introduction and "Redis is down", and `RedisDownTest` | AC-1 |
| 3 | `phase-3/spec-029-3` | "The manifest was evicted", and `ManifestEvictedTest` | AC-2 |
| 4 | `phase-3/spec-029-4` | "A bad rule blocks /api", and `BadRuleTest` | AC-3 |
| 5 | `phase-3/spec-029-5` | The `emergency` check (red tests first), "An emergency change was left on", `EmergencyLeftOnTest`, the links from the README and the guide, the changelog, and the spec's close | AC-4 to AC-6 |

## Open questions

None. Decision 2, the `emergency` check and its 24 hours, is the one to veto if you disagree.
