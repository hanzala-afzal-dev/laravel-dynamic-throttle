<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

use Afzal\DynamicThrottle\Manifest\Manifest;

/**
 * The manifest servers read, as the health checks found it: read, missing, or
 * not readable, with why.
 *
 * @internal
 */
final readonly class ManifestRead
{
    private function __construct(
        public ?Manifest $manifest,
        /** Why it cannot be read, when it is there. */
        public ?string $error,
    ) {}

    public static function of(Manifest $manifest): self
    {
        return new self($manifest, null);
    }

    public static function missing(): self
    {
        return new self(null, null);
    }

    public static function unreadable(string $error): self
    {
        return new self(null, $error);
    }

    /**
     * Why a check that needs the rules cannot have them, or null when it can.
     */
    public function problem(): ?string
    {
        return match (true) {
            $this->manifest instanceof Manifest => null,
            $this->error === null => 'The manifest is missing, so the rules are unknown.',
            default => "The manifest cannot be read, so the rules are unknown: {$this->error}",
        };
    }
}
