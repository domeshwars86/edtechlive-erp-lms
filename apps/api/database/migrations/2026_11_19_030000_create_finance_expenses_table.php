<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('category', 40);
            $table->decimal('amount', 10, 2);
            $table->date('incurred_on');
            $table->string('payment_method', 24);
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'incurred_on']);
            $table->index(['organization_id', 'category', 'incurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expenses');
    }
};
