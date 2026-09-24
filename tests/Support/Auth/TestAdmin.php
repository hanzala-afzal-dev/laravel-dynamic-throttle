<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support\Auth;

use Illuminate\Auth\GenericUser;

/**
 * A second user model, so tests can give two user types the same id.
 */
final class TestAdmin extends GenericUser {}
