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
        Schema::create('models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('digest')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('family')->nullable();
            $table->string('parameter_size')->nullable();
            $table->string('quantization')->nullable();
            $table->boolean('available')->default(true);
            $table->timestamps();

            $table->unique(['domain_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('models');
    }
};
