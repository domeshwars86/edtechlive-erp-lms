<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_section_id')->constrained('academic_sections')->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('status', 16);
            $table->string('note', 500)->nullable();
            $table->foreignId('marked_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'academic_section_id', 'student_id', 'attendance_date']);
            $table->index(['organization_id', 'academic_session_id', 'attendance_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
