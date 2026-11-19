<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_membership_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_membership_id')->constrained('organization_memberships')->cascadeOnDelete();
            $table->string('permission_key', 120);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_membership_id', 'permission_key'], 'org_membership_permissions_unique');
            $table->index('permission_key');
        });

        Schema::table('organization_access_audits', function (Blueprint $table): void {
            $table->string('event_type', 32)->default('membership');
            $table->json('previous_permissions')->nullable();
            $table->json('permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organization_access_audits', function (Blueprint $table): void {
            $table->dropColumn(['event_type', 'previous_permissions', 'permissions']);
        });

        Schema::dropIfExists('organization_membership_permissions');
    }
};
