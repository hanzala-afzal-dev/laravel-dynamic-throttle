<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Enums\AuditAction;
use Afzal\DynamicThrottle\Models\AuditLogEntry;
use Afzal\DynamicThrottle\Support\TableMissing;
use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule as ValidationRule;

/**
 * GET api/audit (SPEC-019): the audit log, newest first, in cursor pages of 100, so
 * a page costs the same at any depth and no page counts the table (decision 2),
 * narrowed by action, target, actor and a time range (decision 3).
 *
 * @internal
 */
final readonly class AuditController
{
    /** Entries a page (decision 2). */
    public const int PER_PAGE = 100;

    /** The earliest and latest times a filter takes, so every year has four digits. */
    private const string EARLIEST = '1000-01-01';

    private const string LATEST = '9999-01-01';

    /** The filters that match a column exactly. */
    private const array EXACT = ['action', 'target', 'actor'];

    public function __construct(
        private ValidationFactory $validation,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $model = new AuditLogEntry;

            if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
                throw TableMissing::named($model->getTable());
            }

            $query = $request->query();
            $filters = $this->validation->make($query, [
                'action' => ['sometimes', 'string', ValidationRule::enum(AuditAction::class)],
                'target' => ['sometimes', 'string', 'max:255'],
                'actor' => ['sometimes', 'string', 'max:255'],
                // Four-digit years: the table's times compare as text on SQLite (AC-8).
                'from' => ['sometimes', 'date', 'after:'.self::EARLIEST, 'before:'.self::LATEST],
                'until' => ['sometimes', 'date', 'after:'.self::EARLIEST, 'before:'.self::LATEST, ...(isset($query['from']) ? ['after_or_equal:from'] : [])],
                'cursor' => ['sometimes', 'string', $this->cursor(...)],
            ])->validate();
            $entries = AuditLogEntry::query()->orderByDesc('id');

            foreach (self::EXACT as $field) {
                if (is_string($filters[$field] ?? null)) {
                    $entries->where($field, $filters[$field]);
                }
            }

            foreach (['from' => '>=', 'until' => '<='] as $field => $operator) {
                if (is_string($filters[$field] ?? null)) {
                    // The table stores its times in UTC (SPEC-013 AC-26).
                    $entries->where('created_at', $operator, Date::parse($filters[$field])->setTimezone('UTC')->format('Y-m-d H:i:s'));
                }
            }

            // The checked cursor, from the query: Laravel would read the input, which a JSON body fills (AC-8).
            $cursor = is_string($filters['cursor'] ?? null) ? Cursor::fromEncoded($filters['cursor']) : null;
            $page = $entries->cursorPaginate(self::PER_PAGE, cursor: $cursor ?? '')->through(fn (AuditLogEntry $entry): array => Payloads::audit($entry));

            // A user agent is stored as the request sent it, which may not be UTF-8 (AC-8).
            return new JsonResponse($page, 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
        });
    }

    /**
     * A cursor this endpoint gave: Laravel reads any other as the first page, or fails.
     *
     * @param  Closure(string): mixed  $fail
     */
    private function cursor(string $attribute, mixed $value, Closure $fail): void
    {
        $cursor = is_string($value) ? Cursor::fromEncoded($value) : null;
        $id = $cursor instanceof Cursor ? ($cursor->toArray()['id'] ?? null) : null;

        // A whole number in the id column's range, so the query neither throws nor compares text (AC-8).
        if (! is_int($id) || $id < 1) {
            $fail('The cursor is not one this list gave: use next_cursor from the page before.');
        }
    }
}
