<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Dashboard\Links\Links;
use Afzal\DynamicThrottle\Dashboard\Links\LinkToken;
use Afzal\DynamicThrottle\Http\DashboardPath;
use Afzal\DynamicThrottle\Models\DashboardLink;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Afzal\DynamicThrottle\Support\TableMissing;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * dynamic-throttle:link (SPEC-034, ADR-0031): makes a one-time link that opens the
 * dashboard, lists the links that have not ended, and ends one or all of them. Only
 * this command makes links, so whoever makes one has a shell on the server
 * (decision 3).
 *
 * @internal
 */
final class LinkCommand extends ChangeCommand
{
    /** The most characters a link's name can have, as the table's column holds. */
    private const int NAME_LENGTH = 100;

    /** No control character, such as a terminal escape, and no invisible format character, such as a right-to-left override. */
    private const string NO_CONTROL = '/\A[^\p{Cc}\p{Cf}]*\z/u';

    protected $signature = 'dynamic-throttle:link
        {--name= : Who the link is for, such as "alice, incident 42"}
        {--for=60m : How long it works, such as 30m, 2h or 1d, at most 1d}
        {--manage : Let it change rules, exemptions and settings, not only view them}
        {--list : List the links that have not ended}
        {--revoke= : End the link with this id}
        {--revoke-all : End every link that has not ended}
        {--force : Do not ask for confirmation in production}';

    protected $description = 'Make a one-time link that opens the dashboard, or list and end links';

    public function handle(EmergencyControls $controls, Config $config): int
    {
        $modes = array_keys(array_filter([
            'make' => $this->option('name') !== null,
            'list' => $this->option('list') === true,
            'revoke' => $this->option('revoke') !== null,
            'revoke-all' => $this->option('revoke-all') === true,
        ]));

        if (count($modes) > 1) {
            return $this->refuse('Pass one of --name, --list, --revoke or --revoke-all.');
        }

        $path = DashboardPath::of($config);

        if ($path === null) {
            return $this->refuse('The dashboard is off (dynamic-throttle.dashboard.enabled), so a link would open nothing.');
        }

        // Before the table is read, so an app that never turns links on needs no migration (decision 2).
        if (! Links::enabled($config)) {
            return $this->refuse('Links are off: set DYNAMIC_THROTTLE_DASHBOARD_LINKS=true (dynamic-throttle.dashboard.links) to use them.');
        }

        $model = new DashboardLink;

        try {
            if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
                return $this->refuse(TableMissing::named($model->getTable())->getMessage());
            }
        } catch (Throwable $exception) {
            return $this->refuse('Could not read the links: '.$exception->getMessage());
        }

        return match ($modes[0] ?? 'make') {
            'list' => $this->list(),
            'revoke' => $this->revoke($controls),
            'revoke-all' => $this->revokeAll($controls),
            default => $this->make($controls, $config, $path),
        };
    }

    private function make(EmergencyControls $controls, Config $config, string $path): int
    {
        $name = $this->option('name');
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            return $this->refuse('--name is required: who the link is for, such as "alice, incident 42".');
        }

        // Checked first: the pattern also refuses text that is not UTF-8, which nothing could count.
        if (preg_match(self::NO_CONTROL, $name) !== 1) {
            return $this->refuse('--name must be text without control characters or invisible ones, such as a right-to-left override.');
        }

        if (mb_strlen($name) > self::NAME_LENGTH) {
            return $this->refuse('--name can be at most '.self::NAME_LENGTH.' characters, got '.mb_strlen($name).'.');
        }

        $for = $this->option('for');
        $for = is_string($for) ? $for : '';

        try {
            $seconds = Duration::seconds($for);
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        if ($seconds > Links::MAX_SECONDS) {
            return $this->refuse("--for can be at most 1d for a link, got \"{$for}\".");
        }

        $until = Date::now()->addSeconds($seconds);
        $manage = $this->option('manage') === true;

        if ($manage && ! $this->confirmed("The link lets whoever holds it change rules, exemptions and settings until {$this->time($until)}")) {
            return self::FAILURE;
        }

        $token = LinkToken::make();

        try {
            $link = $controls->createLink($name, LinkToken::hash($token), $manage, $until, Actor::cli());
        } catch (Throwable $exception) {
            return $this->failed($exception);
        }

        $url = Links::url($config, $path, $token);
        $allows = $link->can_manage ? 'can view and change rules, exemptions and settings' : 'view only';

        $this->info(Printable::text("Link {$link->id} for \"{$link->name}\": {$allows}, until {$this->time($link->expires_at)}."));
        $this->line(Printable::text($url));
        $this->line('It works once, in one browser. Send it privately: until it is used, anyone who has it can open the dashboard.');
        $this->line("End it early with: php artisan dynamic-throttle:link --revoke={$link->id}");

        if (str_starts_with($url, 'http://') && ! $this->laravel->environment('local')) {
            $this->warn('The link starts with http://, so anyone on the network can read it: set APP_URL to an https:// address.');
        }

        return self::SUCCESS;
    }

    private function list(): int
    {
        try {
            $links = DashboardLink::query()->active()->orderByDesc('id')->get();
        } catch (Throwable $exception) {
            return $this->refuse('Could not read the links: '.$exception->getMessage());
        }

        if ($links->isEmpty()) {
            $this->info('No link is active.');

            return self::SUCCESS;
        }

        $this->table(['Id', 'For', 'Can', 'Used', 'Until', 'Created by'], $links->map(fn (DashboardLink $link): array => [
            (string) $link->id,
            Printable::text($link->name),
            $link->can_manage ? 'view and manage' : 'view',
            $link->used_at instanceof CarbonInterface ? $this->time($link->used_at) : 'not yet',
            $this->time($link->expires_at),
            Printable::text($link->created_by),
        ])->all());

        return self::SUCCESS;
    }

    private function revoke(EmergencyControls $controls): int
    {
        $given = $this->option('revoke');
        $id = filter_var($given, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            return $this->refuse('--revoke must be a link\'s id, a whole number, got "'.(is_scalar($given) ? (string) $given : '').'".');
        }

        try {
            $link = DashboardLink::query()->find($id);

            if (! $link instanceof DashboardLink) {
                return $this->refuse("No link has the id {$id}.");
            }

            $ended = $controls->revokeLink($link, Actor::cli());
        } catch (Throwable $exception) {
            return $this->failed($exception);
        }

        $this->info($ended ? Printable::text("Link {$id} for \"{$link->name}\" has ended.") : "Link {$id} has already ended.");

        return self::SUCCESS;
    }

    private function revokeAll(EmergencyControls $controls): int
    {
        try {
            $count = $controls->revokeLinks(Actor::cli());
        } catch (Throwable $exception) {
            return $this->failed($exception);
        }

        $this->info($count === 0 ? 'No link is active.' : "Ended {$count} ".Str::plural('link', $count).'.');

        return self::SUCCESS;
    }

    private function failed(Throwable $exception): int
    {
        return $this->refuse($exception instanceof TableMissing ? $exception->getMessage() : 'Could not save the change: '.$exception->getMessage());
    }
}
