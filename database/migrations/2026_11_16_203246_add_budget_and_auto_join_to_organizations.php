<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('monthly_budget_cents')->nullable()->after('bot_handle');
            $table->json('auto_join_domains')->nullable()->after('monthly_budget_cents');
            $table->string('auto_join_role')->default('member')->after('auto_join_domains');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['monthly_budget_cents', 'auto_join_domains', 'auto_join_role']);
        });
    }
};
