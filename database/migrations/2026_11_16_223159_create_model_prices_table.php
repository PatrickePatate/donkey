<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('model');
            $table->decimal('input_per_million', 12, 4);
            $table->decimal('output_per_million', 12, 4);
            $table->timestamps();

            $table->unique(['organization_id', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_prices');
    }
};
