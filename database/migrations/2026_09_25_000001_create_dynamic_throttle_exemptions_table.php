<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The exemptions table (SPEC-009, ADR-0007). The subject is a string validated
| by the package's backed enum, never a database enum, so adding one needs no
| migration. A published migration must not depend on package classes.
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
        Schema::create('dynamic_throttle_exemptions', function (Blueprint $table): void {
            $table->id();
            // Null exempts from every rule (ADR-0008). A deleted rule takes its exemptions with it.
            $table->foreignId('rule_id')->nullable()->constrained('dynamic_throttle_rules')->cascadeOnDelete();
            $table->string('subject', 16);
            // The custom scope's name.
            $table->string('subject_parameter', 64)->nullable();
            // An address or range, Class:id, or a custom scope's value.
            $table->string('value', 255);
            $table->string('reason', 255)->nullable();
            // A datetime, not a timestamp: MySQL's TIMESTAMP ends in January 2038.
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_throttle_exemptions');
    }
};
