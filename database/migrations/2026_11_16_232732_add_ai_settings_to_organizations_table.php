<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('ai_provider')->nullable();
            $table->text('ai_api_key')->nullable();
            $table->string('review_model')->nullable();
            $table->string('eco_model')->nullable();
            $table->string('verifier_model')->nullable();
            $table->string('conversation_model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_api_key', 'review_model', 'eco_model', 'verifier_model', 'conversation_model']);
        });
    }
};
