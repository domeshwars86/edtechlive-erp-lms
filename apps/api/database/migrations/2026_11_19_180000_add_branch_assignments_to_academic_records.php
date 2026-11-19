<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['academic_classes', 'students', 'teachers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('organization_branch_id')->nullable()->after('organization_id')
                    ->constrained('organization_branches')->nullOnDelete();
                $table->index(['organization_id', 'organization_branch_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['teachers', 'students', 'academic_classes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['organization_branch_id']);
                $table->dropIndex("{$tableName}_organization_id_organization_branch_id_index");
                $table->dropColumn('organization_branch_id');
            });
        }
    }
};
