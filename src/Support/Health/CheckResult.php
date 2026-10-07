<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

use JsonSerializable;

/**
 * One check's finding: its id and status, which are public once released, and a
 * message for people, which is not (SPEC-016 decision 2).
 *
 * @internal
 */
final readonly class CheckResult implements JsonSerializable
{
    public function __construct(
        public string $check,
        public CheckStatus $status,
        public string $message,
    ) {}

    public static function ok(string $check, string $message): self
    {
        return new self($check, CheckStatus::Ok, $message);
    }

    public static function warning(string $check, string $message): self
    {
        return new self($check, CheckStatus::Warning, $message);
    }

    public static function error(string $check, string $message): self
    {
        return new self($check, CheckStatus::Error, $message);
    }

    public static function skipped(string $check, string $message): self
    {
        return new self($check, CheckStatus::Skipped, $message);
    }

    /**
     * @return array{check: string, status: string, message: string}
     */
    public function jsonSerialize(): array
    {
        return ['check' => $this->check, 'status' => $this->status->value, 'message' => $this->message];
    }
}
