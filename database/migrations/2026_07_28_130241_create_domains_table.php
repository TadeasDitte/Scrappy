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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('host')->unique();
            $table->string('scheme')->default('https');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedInteger('model_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_probed_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'response_time_ms']);
            $table->index(['is_active', 'model_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
