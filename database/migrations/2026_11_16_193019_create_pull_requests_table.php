<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pull_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_id');
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('author_login');
            $table->string('state')->default('open');
            $table->boolean('is_draft')->default(false);
            $table->string('head_sha', 40);
            $table->string('base_sha', 40);
            $table->string('head_ref');
            $table->string('base_ref');
            $table->string('html_url');
            $table->string('last_reviewed_sha', 40)->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pull_requests');
    }
};
