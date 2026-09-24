<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support\Auth;

use Illuminate\Auth\GenericUser;

/**
 * A user model for scope tests: no database, the id is whatever the test sets.
 */
final class TestUser extends GenericUser {}
