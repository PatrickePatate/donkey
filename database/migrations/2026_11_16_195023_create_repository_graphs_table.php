<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repository_graphs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('commit_sha', 40);
            $table->string('status');
            $table->string('path')->nullable();
            $table->unsignedInteger('nodes_count')->default(0);
            $table->unsignedInteger('edges_count')->default(0);
            $table->unsignedInteger('files_count')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('is_incremental')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'commit_sha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_graphs');
    }
};
