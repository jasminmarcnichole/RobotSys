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
        Schema::create('onboard_vision_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 50);
            $table->char('boot_id', 16);
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->unsignedBigInteger('last_timestamp_ms')->default(0);
            $table->timestamps();
            $table->unique(['device_id', 'boot_id']);
            $table->index(['device_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboard_vision_sessions');
    }
};
