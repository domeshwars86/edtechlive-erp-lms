<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 32)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('contact_email', 254)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_branches');
    }
};
