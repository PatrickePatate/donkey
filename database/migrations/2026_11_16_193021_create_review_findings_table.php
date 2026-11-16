<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 40);
            $table->string('category');
            $table->string('severity');
            $table->unsignedTinyInteger('confidence');
            $table->unsignedTinyInteger('risk_score');
            $table->string('file_path');
            $table->unsignedInteger('start_line');
            $table->unsignedInteger('end_line');
            $table->string('title');
            $table->text('body');
            $table->text('suggestion')->nullable();
            $table->text('code')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('github_comment_id')->nullable();
            $table->timestamps();

            $table->index(['pull_request_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_findings');
    }
};
