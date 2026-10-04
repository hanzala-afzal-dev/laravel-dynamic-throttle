<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The audit log (ADR-0015 point 3), shipped with SPEC-013 so the CLI's changes
| are audited. Append-only: entries are written, never changed, so the table
| has no updated_at. A published migration must not depend on package classes.
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
        Schema::create('dynamic_throttle_audit_log', function (Blueprint $table): void {
            $table->id();
            // cli:<system user> for the CLI.
            $table->string('actor', 255);
            $table->string('action', 64);
            // What changed, such as setting:bypass_all or rule:12.
            $table->string('target', 255);
            // Text, not json: MySQL's JSON type reorders object keys, so an entry would not come back as written.
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            // 45 characters hold any IPv6 address, an IPv4-mapped one included.
            $table->string('ip', 45)->nullable();
            // Text: browsers' user agents can be longer than any string column's limit.
            $table->text('user_agent')->nullable();
            // A datetime, not a timestamp: MySQL's TIMESTAMP ends in January 2038.
            $table->dateTime('created_at');
            $table->index('created_at');
            $table->index('target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_throttle_audit_log');
    }
};
