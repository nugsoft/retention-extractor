<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A licence can now belong to one branch of a client rather than the whole
 * of it, where the product bills per branch. Each branch keeps its own last
 * applied version, so the remembered row is keyed on both.
 *
 * Empty rather than null for the whole client: a unique key treats every
 * null as different from every other, and would let the client's own row be
 * written twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retention_licences', function (Blueprint $table): void {
            $table->string('branch_external_id', 100)->default('')->after('external_id');
            $table->unique(['external_id', 'branch_external_id']);
        });

        Schema::table('retention_licences', function (Blueprint $table): void {
            $table->dropUnique(['external_id']);
        });
    }

    public function down(): void
    {
        // Branch rows have no place in the old shape: one row per client.
        DB::table('retention_licences')->where('branch_external_id', '!=', '')->delete();

        Schema::table('retention_licences', function (Blueprint $table): void {
            $table->unique('external_id');
        });

        Schema::table('retention_licences', function (Blueprint $table): void {
            $table->dropUnique(['external_id', 'branch_external_id']);
            $table->dropColumn('branch_external_id');
        });
    }
};
