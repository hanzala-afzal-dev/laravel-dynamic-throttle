<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The rules table (SPEC-005, ADR-0007). Enum columns are strings validated by
| the package's backed enums, never database enums, so adding a value needs
| no migration. Defaults are literals: a published migration must not depend
| on package classes that may change.
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
        Schema::create('dynamic_throttle_rules', function (Blueprint $table): void {
            // Counters use the id as the rule id (ADR-0006); auto-increment ids are never reused.
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('method', 7)->default('*');
            $table->string('pattern', 255);
            $table->string('scope', 32);
            $table->string('scope_parameter', 64)->nullable();
            $table->unsignedInteger('max_attempts');
            $table->unsignedInteger('window_seconds');
            $table->unsignedInteger('priority')->default(100);
            $table->string('mode', 16)->default('enforce');
            $table->string('on_failure', 16)->default('open');
            $table->string('on_missing_identity', 16)->default('ip');
            // Text, not json: MySQL's JSON type reorders object keys, so the body would not come back as written.
            $table->text('response_override')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_throttle_rules');
    }
};
