<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('organization_invitation_students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_invitation_id')->constrained('organization_invitations')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_invitation_id', 'student_id'], 'invitation_student_unique');
            $table->index(['organization_id', 'student_id']);
        });

        Schema::create('student_guardians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('relationship', 40)->default('parent');
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('linked_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'student_id', 'guardian_user_id'], 'student_guardian_unique');
            $table->index(['organization_id', 'guardian_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('organization_invitation_students');
        Schema::table('students', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
