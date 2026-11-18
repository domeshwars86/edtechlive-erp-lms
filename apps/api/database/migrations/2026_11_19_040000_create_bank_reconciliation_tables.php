<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('account_label', 120);
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('opening_balance', 12, 2);
            $table->decimal('closing_balance', 12, 2);
            $table->unsignedInteger('line_count');
            $table->timestampsTz();
            $table->index(['organization_id', 'period_to']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->date('transacted_on');
            $table->string('description', 240);
            $table->string('direction', 8);
            $table->decimal('amount', 10, 2);
            $table->string('reference', 120)->nullable();
            $table->foreignId('fee_payment_id')->nullable()->unique()->constrained('fee_payments')->restrictOnDelete();
            $table->foreignId('finance_expense_id')->nullable()->unique()->constrained('finance_expenses')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['bank_statement_id', 'transacted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
