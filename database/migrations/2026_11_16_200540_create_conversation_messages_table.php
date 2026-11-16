<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pull_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_finding_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('in_reply_to_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->unsignedBigInteger('thread_comment_id')->nullable();
            $table->unsignedBigInteger('github_comment_id')->nullable()->unique();
            $table->string('role');
            $table->string('author_login');
            $table->text('body');
            $table->string('status')->default('answered');
            $table->unsignedInteger('tokens_input')->default(0);
            $table->unsignedInteger('tokens_output')->default(0);
            $table->timestamps();

            $table->index(['pull_request_id', 'thread_comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
