<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The runtime settings (SPEC-013, ADR-0011): one row per setting that is on,
| named by the package's backed enum, never a database enum, so adding a
| setting needs no migration. Turning a setting off deletes its row. A
| published migration must not depend on package classes.
*/

return new class extends Migration
{
    /**
     * The connection from config('dynamic-throttle.database.connection'), or the app's default.
     */
    public function getConnection(): ?string
    {
        $connection = config('dynamic-throttle.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::create('dynamic_throttle_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 32)->unique();
            // "1" for bypass_all, the factor for scale_factor, such as "1.5".
            $table->string('value', 32);
            // A datetime, not a timestamp: MySQL's TIMESTAMP ends in January 2038.
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_throttle_settings');
    }
};
