<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->string('graph_status')->nullable()->after('risk_threshold');
            $table->string('graph_risk_level')->nullable()->after('graph_status');
            $table->unsignedTinyInteger('graph_risk_score')->nullable()->after('graph_risk_level');
            $table->jsonb('graph_summary')->nullable()->after('graph_risk_score');
            $table->unsignedInteger('findings_rejected')->default(0)->after('files_reviewed');
        });

        Schema::table('review_findings', function (Blueprint $table) {
            $table->string('graph_symbol')->nullable()->after('code');
            $table->unsignedInteger('graph_callers')->nullable()->after('graph_symbol');
            $table->string('verification')->nullable()->after('status');
            $table->text('verifier_note')->nullable()->after('verification');
        });
    }

    public function down(): void
    {
        Schema::table('review_findings', function (Blueprint $table) {
            $table->dropColumn(['graph_symbol', 'graph_callers', 'verification', 'verifier_note']);
        });

        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn(['graph_status', 'graph_risk_level', 'graph_risk_score', 'graph_summary', 'findings_rejected']);
        });
    }
};
