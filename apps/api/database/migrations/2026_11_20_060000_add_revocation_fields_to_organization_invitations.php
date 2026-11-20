<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->timestampTz('revoked_at')->nullable()->after('accepted_at');
            $table->foreignId('revoked_by')->nullable()->after('accepted_by')->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable()->after('revoked_by');
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['revoked_at', 'revocation_reason']);
        });
    }
};
