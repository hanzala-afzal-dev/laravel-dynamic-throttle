<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Telemetry's hourly numbers (ADR-0013 point 4, SPEC-015), which
| dynamic-throttle:flush-metrics adds to every minute and
| dynamic-throttle:prune deletes. One row per rule and hour. A published
| migration must not depend on package classes.
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
        Schema::create('dynamic_throttle_metrics_hourly', function (Blueprint $table): void {
            $table->id();
            // No foreign key: a rule's rows outlive it until they are pruned (decision 10).
            $table->unsignedBigInteger('rule_id');
            // The hour's start, in UTC (decision 9). A datetime, not a timestamp: MySQL's TIMESTAMP ends in January 2038.
            $table->dateTime('hour');
            $table->unsignedBigInteger('hits')->default(0);
            $table->unsignedBigInteger('blocks')->default(0);
            $table->unsignedBigInteger('would_blocks')->default(0);
            // How many different clients the rule blocked or would have blocked; null where the driver keeps no count.
            $table->unsignedBigInteger('clients')->nullable();
            // The last minute added, in Unix minutes, so that no flush adds a minute twice (decision 8).
            $table->unsignedBigInteger('flushed_minute')->default(0);
            $table->unique(['rule_id', 'hour']);
            $table->index('hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_throttle_metrics_hourly');
    }
};
