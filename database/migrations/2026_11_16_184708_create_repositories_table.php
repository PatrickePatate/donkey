<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_installation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_id')->unique();
            $table->string('full_name');
            $table->string('default_branch')->default('main');
            $table->boolean('is_private')->default(true);
            $table->boolean('auto_review_enabled')->nullable();
            $table->unsignedTinyInteger('risk_threshold')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'full_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
