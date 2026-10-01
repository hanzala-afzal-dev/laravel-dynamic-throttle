<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Enums\SettingName;
use InvalidArgumentException;

/**
 * The runtime settings the manifest holds (ADR-0011, SPEC-013): bypass, and the
 * scale factor as a whole percentage, each with an optional end time in Unix
 * seconds. A setting applies while the time is before its end, and not at it,
 * so a forgotten change ends on time even when no compile runs.
 */
final readonly class ManifestSettings
{
    /** The factor's bounds, 0.1 and 10, as percentages (ADR-0011 point 1). */
    public const int MIN_PERCENT = 10;

    public const int MAX_PERCENT = 1_000;

    /**
     * @param  bool  $bypass  every request goes through, unlimited
     * @param  ?int  $bypassUntil  when bypass ends, or null for no end time
     * @param  ?int  $scale  the factor as a percentage, 150 for 1.5, or null for none
     * @param  ?int  $scaleUntil  when the factor ends, or null for no end time
     *
     * @throws InvalidArgumentException for a factor outside 0.1 to 10, or an end time for a setting that is off
     */
    public function __construct(
        public bool $bypass = false,
        public ?int $bypassUntil = null,
        public ?int $scale = null,
        public ?int $scaleUntil = null,
    ) {
        if ($scale !== null && ($scale < self::MIN_PERCENT || $scale > self::MAX_PERCENT)) {
            throw new InvalidArgumentException('The scale factor must be from '.self::MIN_PERCENT.' to '.self::MAX_PERCENT." percent, got {$scale}.");
        }

        if (! $bypass && $bypassUntil !== null) {
            throw new InvalidArgumentException('Bypass has an end time, but is off.');
        }

        if ($scale === null && $scaleUntil !== null) {
            throw new InvalidArgumentException('The scale factor has an end time, but is off.');
        }
    }

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  int  $now  Unix seconds
     */
    public function bypasses(int $now): bool
    {
        return $this->bypass && ($this->bypassUntil === null || $now < $this->bypassUntil);
    }

    /**
     * The factor as a percentage at a time, 100 for none.
     *
     * @param  int  $now  Unix seconds
     */
    public function scalePercent(int $now): int
    {
        return $this->scale !== null && ($this->scaleUntil === null || $now < $this->scaleUntil) ? $this->scale : 100;
    }

    /**
     * The settings that still apply at a time: those that ended are off. The compiler
     * leaves ended settings out, and dynamic-throttle:status compares what applies.
     *
     * @param  int  $now  Unix seconds
     */
    public function at(int $now): self
    {
        $bypass = $this->bypasses($now);
        $scale = $this->scale !== null && ($this->scaleUntil === null || $now < $this->scaleUntil);

        return new self($bypass, $bypass ? $this->bypassUntil : null, $scale ? $this->scale : null, $scale ? $this->scaleUntil : null);
    }

    /**
     * Each setting that is on, by name.
     *
     * @return array<string, array<string, int|null>>
     */
    public function toArray(): array
    {
        $settings = [];

        if ($this->bypass) {
            $settings[SettingName::BypassAll->value] = ['until' => $this->bypassUntil];
        }

        if ($this->scale !== null) {
            $settings[SettingName::ScaleFactor->value] = ['percent' => $this->scale, 'until' => $this->scaleUntil];
        }

        return $settings;
    }

    /**
     * @throws InvalidArgumentException naming what is wrong
     */
    public static function fromArray(mixed $settings): self
    {
        // JSON's empty object decodes to an empty array.
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw new InvalidArgumentException('The settings must be an object, keyed by setting.');
        }

        foreach (array_keys($settings) as $name) {
            if (SettingName::tryFrom((string) $name) === null) {
                throw new InvalidArgumentException("The manifest has a setting it does not know: \"{$name}\".");
            }
        }

        $bypass = $settings[SettingName::BypassAll->value] ?? null;
        $scale = $settings[SettingName::ScaleFactor->value] ?? null;

        return new self(
            bypass: $bypass !== null,
            bypassUntil: $bypass === null ? null : self::until(self::fields($bypass, SettingName::BypassAll, ['until']), SettingName::BypassAll),
            scale: $scale === null ? null : self::percent(self::fields($scale, SettingName::ScaleFactor, ['percent', 'until'])),
            scaleUntil: $scale === null ? null : self::until(self::fields($scale, SettingName::ScaleFactor, ['percent', 'until']), SettingName::ScaleFactor),
        );
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function fields(mixed $setting, SettingName $name, array $keys): array
    {
        if (! is_array($setting) || array_keys($setting) !== $keys) {
            throw new InvalidArgumentException("{$name->value} must have exactly the keys ".implode(' and ', $keys).'.');
        }

        /** @var array<string, mixed> $setting */
        return $setting;
    }

    /**
     * @param  array<string, mixed>  $setting
     *
     * @throws InvalidArgumentException
     */
    private static function until(array $setting, SettingName $name): ?int
    {
        $until = $setting['until'];

        return $until === null || is_int($until) ? $until : throw new InvalidArgumentException("{$name->value}'s until must be an integer or null.");
    }

    /**
     * @param  array<string, mixed>  $setting
     *
     * @throws InvalidArgumentException
     */
    private static function percent(array $setting): int
    {
        return is_int($setting['percent']) ? $setting['percent'] : throw new InvalidArgumentException('scale_factor\'s percent must be an integer.');
    }
}
