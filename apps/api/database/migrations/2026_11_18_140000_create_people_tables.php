<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('admission_number', 40);
            $table->string('email', 254)->nullable();
            $table->string('phone', 24)->nullable();
            $table->foreignId('academic_class_id')->nullable()->constrained('academic_classes')->nullOnDelete();
            $table->foreignId('academic_section_id')->nullable()->constrained('academic_sections')->nullOnDelete();
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->date('enrolled_on')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'admission_number']);
            $table->index(['organization_id', 'academic_class_id', 'academic_session_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('teachers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('employee_number', 40)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 24)->nullable();
            $table->string('specialization', 120)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'employee_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
        Schema::dropIfExists('students');
    }
};
