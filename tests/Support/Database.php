<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * The package's database when it cannot be reached: its connection's PDO throws the
 * driver's error on every try, so Laravel's reconnect fails too, as it does with a
 * server that is down.
 */
final class Database
{
    public const string CONNECTION = 'dynamic-throttle-down';

    /** MySQL's error when nothing listens on the server's port. */
    public const string REFUSED = 'SQLSTATE[HY000] [2002] Connection refused';

    public static function down(string $error = self::REFUSED): void
    {
        DB::extend(self::CONNECTION, fn (array $config, string $name): SQLiteConnection => new SQLiteConnection(
            fn (): never => throw new PDOException($error),
            ':memory:',
            '',
            [...$config, 'name' => $name],
        ));
        config()->set('database.connections.'.self::CONNECTION, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('dynamic-throttle.database.connection', self::CONNECTION);
    }
}
