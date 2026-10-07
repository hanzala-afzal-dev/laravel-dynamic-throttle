<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support\Http;

use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * A controller the container cannot build, as one whose constructor throws or
 * that needs an unbound interface is. Laravel builds a controller to read its
 * middleware, so reading the route's middleware throws (SPEC-016's review).
 */
final class UnbuildableController extends Controller
{
    public const string MESSAGE = 'The controller cannot be built.';

    public function __construct()
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function __invoke(): string
    {
        return 'ok';
    }
}
