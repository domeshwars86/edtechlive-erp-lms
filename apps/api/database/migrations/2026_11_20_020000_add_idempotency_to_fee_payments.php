<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->char('idempotency_fingerprint', 64)->nullable();
            $table->unique(['organization_id', 'idempotency_key'], 'fee_payments_org_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fee_payments', function (Blueprint $table): void {
            $table->dropUnique('fee_payments_org_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};
