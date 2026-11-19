<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->foreignId('academic_class_id')->constrained('academic_classes')->restrictOnDelete();
            $table->foreignId('academic_section_id')->nullable()->constrained('academic_sections')->nullOnDelete();
            $table->string('name', 120);
            $table->string('code', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'academic_session_id', 'academic_class_id', 'name'], 'academic_batches_name_scope_unique');
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'academic_session_id', 'is_active']);
            $table->index(['organization_id', 'academic_class_id', 'academic_section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_batches');
    }
};
