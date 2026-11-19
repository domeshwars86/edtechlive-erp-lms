<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('slug', 180)->nullable();
            $table->boolean('is_public_listing')->default(false);
        });

        DB::table('courses')->orderBy('organization_id')->orderBy('id')->get(['id', 'organization_id', 'title'])->each(function (object $course): void {
            $base = Str::slug($course->title) ?: 'course';
            $slug = $base;
            $suffix = 2;
            while (DB::table('courses')->where('organization_id', $course->organization_id)->where('slug', $slug)->where('id', '!=', $course->id)->exists()) {
                $slug = $base.'-'.$suffix++;
            }
            DB::table('courses')->where('id', $course->id)->update(['slug' => $slug]);
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->string('slug', 180)->nullable(false)->change();
            $table->unique(['organization_id', 'slug']);
            $table->index(['is_public_listing', 'status', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'slug']);
            $table->dropIndex(['is_public_listing', 'status', 'organization_id']);
            $table->dropColumn(['slug', 'is_public_listing']);
        });
    }
};
