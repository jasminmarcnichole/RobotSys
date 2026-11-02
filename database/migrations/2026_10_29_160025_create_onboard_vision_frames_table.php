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
        Schema::create('onboard_vision_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboard_vision_session_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 50);
            $table->unsignedBigInteger('sequence');
            $table->string('status', 20);
            $table->unsignedTinyInteger('target_count')->default(0);
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->string('snapshot_path')->nullable();
            $table->char('snapshot_sha256', 64)->nullable();
            $table->timestamps();
            $table->unique(['onboard_vision_session_id', 'sequence'], 'vision_frame_sequence_unique');
            $table->index(['device_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboard_vision_frames');
    }
};
