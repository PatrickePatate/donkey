<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->boolean('auto_docs_enabled')->default(false)->after('auto_review_enabled');
        });

        Schema::create('repository_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('commit_sha')->nullable();
            $table->longText('content')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repository_documents');

        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn('auto_docs_enabled');
        });
    }
};
