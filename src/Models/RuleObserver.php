<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Afzal\DynamicThrottle\Enums\RuleChange;
use Afzal\DynamicThrottle\Events\RuleChanged;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Dispatches RuleChanged when a rule is created, updated or deleted (ADR-0013
 * point 6, SPEC-015).
 *
 * The attributes are taken when the change is made, and the event is dispatched
 * after the transaction commits, or at once outside one, so a change that rolls
 * back dispatches nothing. Changes made in nested transactions are dispatched when
 * the outermost one commits, in the order Laravel runs its after-commit callbacks:
 * the innermost first. Nothing here throws, and a listener that throws is
 * reported: the change stands. Changes made without the model, such as a query's
 * update(), dispatch nothing, as they compile nothing.
 */
final readonly class RuleObserver
{
    /** The columns the event carries, in table order: all but the timestamps. */
    private const array ATTRIBUTES = [
        'id',
        'name',
        'description',
        'method',
        'pattern',
        'scope',
        'scope_parameter',
        'max_attempts',
        'window_seconds',
        'priority',
        'mode',
        'on_failure',
        'on_missing_identity',
        'response_override',
        'enabled',
    ];

    public function __construct(
        private Dispatcher $events,
    ) {}

    public function created(Rule $rule): void
    {
        $this->dispatchAfterCommit($rule, fn (): RuleChanged => new RuleChanged($this->id($rule), $this->name($rule), RuleChange::Created, null, $this->attributes($rule, self::ATTRIBUTES)));
    }

    /**
     * Only the changed attributes, before and after. A save that changes only the
     * timestamps, as touch() does, is no change.
     */
    public function updated(Rule $rule): void
    {
        $this->dispatchAfterCommit($rule, function () use ($rule): ?RuleChanged {
            $changed = array_values(array_intersect(self::ATTRIBUTES, array_keys($rule->getChanges())));

            if ($changed === []) {
                return null;
            }

            // Before the save is finished, the original attributes are still those before it.
            $before = (new Rule)->setRawAttributes($rule->getRawOriginal());

            return new RuleChanged($this->id($rule), $this->name($rule), RuleChange::Updated, $this->attributes($before, $changed), $this->attributes($rule, $changed));
        });
    }

    public function deleted(Rule $rule): void
    {
        $this->dispatchAfterCommit($rule, fn (): RuleChanged => new RuleChanged($this->id($rule), $this->name($rule), RuleChange::Deleted, $this->attributes($rule, self::ATTRIBUTES), null));
    }

    private function id(Rule $rule): string
    {
        $id = $rule->getKey();

        return is_int($id) || is_string($id) ? (string) $id : '';
    }

    /**
     * The name, or '' when the rule was loaded without it.
     */
    private function name(Rule $rule): string
    {
        $name = $this->loaded($rule, 'name') ? $rule->getAttribute('name') : null;

        return is_string($name) ? $name : '';
    }

    /**
     * Plain values, with enums as their values, so a queued listener can serialize them.
     * A rule loaded with some of its columns only gives those (the review).
     *
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function attributes(Rule $rule, array $columns): array
    {
        $attributes = [];

        foreach ($columns as $column) {
            if (! $this->loaded($rule, $column)) {
                continue;
            }

            $value = $rule->getAttribute($column);
            $attributes[$column] = $value instanceof BackedEnum ? $value->value : $value;
        }

        return $attributes;
    }

    /**
     * Whether the model knows the column, as Eloquent decides before it throws a
     * MissingAttributeException: a rule just created knows every one, the database's
     * defaults included.
     */
    private function loaded(Rule $rule, string $column): bool
    {
        return $rule->wasRecentlyCreated || array_key_exists($column, $rule->getAttributes());
    }

    /**
     * Never throws: the change is made by then, and the manifest must still compile
     * after it. A listener that throws is reported, after the commit.
     *
     * @param  Closure(): ?RuleChanged  $event
     */
    private function dispatchAfterCommit(Rule $rule, Closure $event): void
    {
        try {
            $change = $event();

            if (! $change instanceof RuleChanged) {
                return;
            }

            $rule->getConnection()->afterCommit(function () use ($change): void {
                try {
                    $this->events->dispatch($change);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
