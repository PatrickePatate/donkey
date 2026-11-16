<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug', 40);
            $table->string('name');
            $table->string('description')->nullable();
            $table->text('instructions');
            $table->string('model')->nullable();
            $table->json('file_globs')->nullable();
            $table->json('categories')->nullable();
            $table->string('severity_floor')->nullable();
            $table->boolean('run_on_auto_review')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'repository_id', 'slug'])->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_agents');
    }
};
