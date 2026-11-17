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
        Schema::create('security_advisories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('package');
            $table->string('installed_version');
            $table->boolean('is_dev')->default(false);
            $table->string('advisory_id');
            $table->string('title');
            $table->string('cve')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('severity')->nullable()->index();
            $table->text('affected_versions');
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'package', 'advisory_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_advisories');
    }
};
