<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->timestamp('dependencies_scanned_at')->nullable();
            $table->string('dependencies_commit_sha', 40)->nullable();
            $table->unsignedInteger('composer_packages_count')->nullable();
            $table->string('dependencies_scan_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['dependencies_scanned_at', 'dependencies_commit_sha', 'composer_packages_count', 'dependencies_scan_error']);
        });
    }
};
