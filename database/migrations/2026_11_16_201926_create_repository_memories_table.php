<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('repository_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_login');
            $table->text('body');
            $table->string('path_glob')->nullable();
            $table->boolean('pinned')->default(false);
            $table->string('source');
            $table->unsignedBigInteger('github_comment_id')->nullable()->unique();
            $table->vector('embedding', config('learning.embeddings.dimensions'))->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_memories');
    }
};
