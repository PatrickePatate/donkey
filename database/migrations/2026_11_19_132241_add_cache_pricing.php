<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('cache_read_tokens')->default(0)->after('output_tokens');
            $table->unsignedBigInteger('cache_write_tokens')->default(0)->after('cache_read_tokens');
        });

        Schema::table('model_prices', function (Blueprint $table) {
            $table->decimal('cache_read_per_million', 12, 4)->nullable()->after('output_per_million');
            $table->decimal('cache_write_per_million', 12, 4)->nullable()->after('cache_read_per_million');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropColumn(['cache_read_tokens', 'cache_write_tokens']);
        });

        Schema::table('model_prices', function (Blueprint $table) {
            $table->dropColumn(['cache_read_per_million', 'cache_write_per_million']);
        });
    }
};
