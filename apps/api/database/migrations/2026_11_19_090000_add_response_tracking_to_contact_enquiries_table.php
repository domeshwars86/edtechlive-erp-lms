<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_enquiries', function (Blueprint $table): void {
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_enquiries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responded_by');
            $table->dropColumn('responded_at');
        });
    }
};
