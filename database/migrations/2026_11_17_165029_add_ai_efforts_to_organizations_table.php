<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the single reasoning effort by one per use, keeping each organization's choice for every use.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('ai_efforts')->nullable();
        });

        DB::table('organizations')->whereNotNull('ai_effort')->eachById(function (object $organization): void {
            DB::table('organizations')->where('id', $organization->id)->update([
                'ai_efforts' => json_encode(array_fill_keys(array_keys(Organization::AI_EFFORT_ROLES), $organization->ai_effort)),
            ]);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('ai_effort');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('ai_effort')->nullable();
            $table->dropColumn('ai_efforts');
        });
    }
};
