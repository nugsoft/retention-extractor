<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_licences', function (Blueprint $table): void {
            $table->id();

            // The id this product knows the client by — whatever it pushes as
            // `external_id`, which is the same value Retention Intel sends back.
            $table->string('external_id', 100)->unique();

            // The last version applied here. A webhook carrying anything older
            // is dropped, which is what stops a retry that arrives late from
            // switching a client back on after they were suspended.
            $table->unsignedInteger('licence_version')->default(0);

            $table->boolean('grants_access')->default(true);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_licences');
    }
};
