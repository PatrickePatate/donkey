<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pull_requests', function (Blueprint $table) {
            $table->boolean('auto_review_paused')->default(false)->after('last_reviewed_sha');
        });

        Schema::table('review_runs', function (Blueprint $table) {
            $table->foreignId('review_agent_id')->nullable()->after('trigger')->constrained()->nullOnDelete();
            $table->boolean('is_dry_run')->default(false)->after('is_full');
        });

        Schema::table('review_findings', function (Blueprint $table) {
            $table->foreignId('review_agent_id')->nullable()->after('pull_request_id')->constrained()->nullOnDelete();
            $table->string('dismissed_by')->nullable()->after('github_comment_id');
            $table->index('github_comment_id');
        });
    }

    public function down(): void
    {
        Schema::table('review_findings', function (Blueprint $table) {
            $table->dropIndex(['github_comment_id']);
            $table->dropConstrainedForeignId('review_agent_id');
            $table->dropColumn('dismissed_by');
        });

        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_agent_id');
            $table->dropColumn('is_dry_run');
        });

        Schema::table('pull_requests', function (Blueprint $table) {
            $table->dropColumn('auto_review_paused');
        });
    }
};
