<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The dashboard's links (SPEC-034, ADR-0031): one row per link that
| dynamic-throttle:link made. A row holds a hash of its token, never the token.
| Ended links stay, as the audit log's entries name them. Only an app that turns
| links on needs this table. A published migration must not depend on package
| classes.
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
        Schema::create('dynamic_throttle_links', function (Blueprint $table): void {
            $table->id();
            // Who the link is for, such as "alice, incident 42".
            $table->string('name', 100);
            // The SHA-256 of the token, in hex.
            $table->char('token_hash', 64)->unique();
            $table->boolean('can_manage');
            // cli:<system user>, as the audit log names the CLI.
            $table->string('created_by', 255);
            // Datetimes, not timestamps: MySQL's TIMESTAMP ends in January 2038.
            $table->dateTime('expires_at')->index();
            $table->dateTime('used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_throttle_links');
    }
};
