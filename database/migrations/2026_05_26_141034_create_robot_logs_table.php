<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_logs', function (Blueprint $table) {
            $table->id();
            $table->string('robot_id')->default('ROBOT-001');
            $table->decimal('battery_level', 5, 2)->default(0);
            $table->string('movement_status')->default('Stopped');
            $table->integer('detected_trash_count')->default(0);
            $table->integer('successful_pickup_count')->default(0);
            $table->integer('failed_pickup_count')->default(0);
            $table->integer('obstacle_count')->default(0);
            $table->integer('operating_duration')->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_logs');
    }
};
