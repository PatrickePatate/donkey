<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->foreignId('review_run_id')->nullable()->after('pull_request_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_run_id');
        });
    }
};
