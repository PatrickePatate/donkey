<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('learnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('status');
            $table->text('body');
            $table->text('rationale')->nullable();
            $table->string('category')->nullable();
            $table->string('path_glob')->nullable();
            $table->json('source_finding_ids')->nullable();
            $table->float('weight')->default(1);
            $table->unsignedInteger('times_applied')->default(0);
            $table->timestamp('last_applied_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->vector('embedding', config('learning.embeddings.dimensions'))->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learnings');
    }
};
