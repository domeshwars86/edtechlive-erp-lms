<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->decimal('amount', 10, 2);
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('unpaid');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'student_id', 'status']);
            $table->index(['organization_id', 'due_on']);
        });

        Schema::create('fee_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_record_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 24);
            $table->string('reference', 120)->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at');
            $table->timestampsTz();
            $table->index(['organization_id', 'fee_record_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
        Schema::dropIfExists('fee_records');
    }
};
