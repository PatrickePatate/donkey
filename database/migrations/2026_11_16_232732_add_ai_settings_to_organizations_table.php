<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

        $this->importEnvironmentSettings();
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_api_key', 'review_model', 'eco_model', 'verifier_model', 'conversation_model']);
        });
    }

    /**
     * Existing installs configured the AI provider through the environment: copy it to every organization once.
     */
    private function importEnvironmentSettings(): void
    {
        $provider = env('REVIEW_AI_PROVIDER') ?: env('AI_PROVIDER') ?: 'anthropic';
        $key = env(strtoupper($provider).'_API_KEY');

        if (blank($key)) {
            return;
        }

        DB::table('organizations')->update([
            'ai_provider' => $provider,
            'ai_api_key' => Crypt::encryptString($key),
            'review_model' => env('REVIEW_AI_MODEL') ?: null,
            'eco_model' => env('REVIEW_ECO_MODEL') ?: null,
            'verifier_model' => env('REVIEW_VERIFIER_MODEL') ?: null,
            'conversation_model' => env('CONVERSATION_AI_MODEL') ?: null,
        ]);
    }
};
