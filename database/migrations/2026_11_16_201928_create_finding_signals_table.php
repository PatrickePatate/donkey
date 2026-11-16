<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finding_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('actor_login')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['review_finding_id', 'type']);
            $table->index(['repository_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_signals');
    }
};
