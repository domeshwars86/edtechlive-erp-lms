<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 32)->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'name']);
        });

        Schema::table('fee_records', function (Blueprint $table): void {
            $table->foreignId('fee_category_id')->nullable()->after('title')->constrained('fee_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fee_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fee_category_id');
        });

        Schema::dropIfExists('fee_categories');
    }
};
