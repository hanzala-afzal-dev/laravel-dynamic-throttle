# During an incident

Two runtime settings change every rule at once, without a deploy, and end by themselves ([ADR-0011](../adr/0011-emergency-controls.md)). They need the package's newest migrations: publish them and run `php artisan migrate`.

```bash
php artisan dynamic-throttle:bypass on --for=30m   # every request goes through, unlimited
php artisan dynamic-throttle:scale 2 --for=2h      # every limit doubles; 0.5 halves them
php artisan dynamic-throttle:status                # what servers apply, and until when
php artisan dynamic-throttle:bypass off
php artisan dynamic-throttle:scale off
```

- **End times.** `--for` takes `90s`, `30m`, `2h` or `1d`, up to 365 days. Without it, a change ends after one hour, and `--forever` sets no end time. Servers stop applying a setting at its end time, by their own clock, even when nothing compiles in between.
- **Scaling.** The factor goes from 0.1 to 10, with at most two decimals. A rule's limit becomes `max(1, ceil(max_attempts × factor))`, a dry-run rule's too, and the counts made before the change still count.
- **One bad rule.** `php artisan dynamic-throttle:list` shows every rule with its id. `dynamic-throttle:disable-rule 12` switches rule 12 off, and `enable-rule 12` brings it back.
- **Confirmation.** In production, turning bypass on, a factor below 1 and disabling an enforcing rule ask first. Scripts pass `--force`.
- **Propagation.** Each change compiles the manifest, and servers apply it within `manifest.refresh_seconds`. If the manifest cannot be written, the change stays saved, the command exits 1, and servers keep the previous manifest until a compile succeeds.
- **The audit log.** Each change writes one append-only entry to `dynamic_throttle_audit_log`: the actor (`cli:` and the system user), the action, the target, and the state before and after.
- **In deploy scripts.** `dynamic-throttle:status` exits 1 when the config is invalid, such as an unknown `driver`, an undefined `store` or an invalid `prefix`.
- **The last resort.** When the database and the cache are both down, set `DYNAMIC_THROTTLE_ENABLED=false` and reload the config: the middleware then does nothing.
