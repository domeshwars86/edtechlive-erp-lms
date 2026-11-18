<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table): void {
            $table->char('fingerprint', 64)->nullable()->after('account_label');
            $table->unique(['organization_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'fingerprint']);
            $table->dropColumn('fingerprint');
        });
    }
};
