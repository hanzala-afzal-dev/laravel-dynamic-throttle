<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Models\Concerns\UsesPackageConnection;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Stringable;

/**
 * Frees a client from one rule, or from every rule, until it expires (SPEC-009,
 * ADR-0008). Requests never read this model: exemptions reach them through the
 * compiled manifest, which leaves out invalid rows (SPEC-006).
 *
 *     Exemption::forIp('10.0.0.0/8')->because('The office network')->save();
 *     Exemption::forUser($user)->forRule($rule)->until(now()->addDay())->save();
 *     Exemption::forCustom('tenant', 'acme')->save();
 *
 * @property int $id
 * @property int|null $rule_id null for every rule
 * @property ExemptionSubject $subject
 * @property string|null $subject_parameter the custom scope's name
 * @property string $value an address or range, Class:id, or a custom scope's value
 * @property string|null $reason
 * @property Carbon|null $expires_at null for never
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Rule|null $rule
 */
final class Exemption extends Model
{
    use UsesPackageConnection;

    protected $table = 'dynamic_throttle_exemptions';

    /** @var list<string> */
    protected $fillable = [
        'rule_id',
        'subject',
        'subject_parameter',
        'value',
        'reason',
        'expires_at',
    ];

    /**
     * An unsaved exemption for an address, such as "203.0.113.7", or a range, such
     * as "10.0.0.0/8" or "2001:db8::/32". It matches the client IP the app's trusted
     * proxies give.
     */
    public static function forIp(string $range): self
    {
        return new self(['subject' => ExemptionSubject::Ip, 'value' => $range]);
    }

    /**
     * An unsaved exemption for the user the user scope finds: its class and id.
     *
     * @throws InvalidArgumentException for a user without a string or integer id
     */
    public static function forUser(Authenticatable $user): self
    {
        $id = $user->getAuthIdentifier();
        $id = $id instanceof Stringable ? (string) $id : $id;

        if ((! is_string($id) && ! is_int($id)) || $id === '') {
            throw new InvalidArgumentException($user::class.'::getAuthIdentifier() returned '.get_debug_type($id).', which no exemption can match.');
        }

        return new self(['subject' => ExemptionSubject::User, 'value' => $user::class.':'.$id]);
    }

    /**
     * An unsaved exemption for a value of a registered custom scope. It matches
     * exactly: "acme" and "ACME" differ.
     */
    public static function forCustom(string $scope, string|int|Stringable $value): self
    {
        return new self(['subject' => ExemptionSubject::Custom, 'subject_parameter' => $scope, 'value' => (string) $value]);
    }

    /**
     * Only for this rule. Without it, the exemption covers every rule.
     *
     * @throws InvalidArgumentException for a rule that is not saved: its missing id would mean every rule
     */
    public function forRule(Rule|int $rule): self
    {
        if ($rule instanceof Rule && ! $rule->exists) {
            throw new InvalidArgumentException('Save the rule before exempting a client from it: an exemption without a rule covers every rule.');
        }

        $this->rule_id = $rule instanceof Rule ? $rule->id : $rule;

        return $this;
    }

    /**
     * Ignored from this time on, even before the next compile, whatever its time zone.
     */
    public function until(DateTimeInterface $expiresAt): self
    {
        $this->setAttribute('expires_at', $expiresAt);

        return $this;
    }

    /**
     * Eloquent stores a date's wall-clock time in the date's own zone, and reads it
     * back in the app's: a date from another zone would move by their difference,
     * and an exemption could outlive its expiry by hours. So every date is moved to
     * the app's zone first, and keeps its instant (SPEC-009 AC-17).
     */
    public function fromDateTime(mixed $value): mixed
    {
        return $value === null || $value === '' ? $value : parent::fromDateTime($this->asDateTime($value)->setTimezone(date_default_timezone_get()));
    }

    /**
     * Why the client is exempt, for admins.
     */
    public function because(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule_id' => 'integer',
            'subject' => ExemptionSubject::class,
            'expires_at' => 'datetime',
        ];
    }
}
