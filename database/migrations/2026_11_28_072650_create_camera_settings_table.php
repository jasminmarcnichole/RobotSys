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
        Schema::create('camera_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('robot_id', 50)->unique();
            $table->string('detection_model', 100)->default('waste_primary_20261026');
            $table->unsignedTinyInteger('confidence_threshold')->default(60);
            $table->boolean('auto_grab_enabled')->default(false);
            $table->unsignedTinyInteger('framesize')->default(8);
            $table->unsignedTinyInteger('quality')->default(14);
            $table->tinyInteger('brightness')->default(0);
            $table->tinyInteger('contrast')->default(1);
            $table->tinyInteger('saturation')->default(0);
            $table->boolean('vflip')->default(true);
            $table->boolean('hmirror')->default(false);
            $table->unsignedTinyInteger('led_intensity')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('camera_settings');
    }
};
