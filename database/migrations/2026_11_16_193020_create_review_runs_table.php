<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('head_sha', 40);
            $table->string('base_sha', 40)->nullable();
            $table->string('trigger');
            $table->string('triggered_by')->nullable();
            $table->boolean('is_full')->default(false);
            $table->string('status')->default('queued');
            $table->string('stage')->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->unsignedTinyInteger('risk_threshold')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedInteger('files_reviewed')->default(0);
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('tokens_input')->default(0);
            $table->unsignedInteger('tokens_output')->default(0);
            $table->unsignedBigInteger('github_review_id')->nullable();
            $table->unsignedBigInteger('github_check_run_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['pull_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_runs');
    }
};
