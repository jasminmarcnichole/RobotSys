<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_commands', function (Blueprint $table) {
            $table->id();
            $table->string('robot_id')->default('ROBOT-001');
            $table->string('command'); // START, STOP, RESET
            $table->string('status')->default('pending'); // pending, received, completed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_commands');
    }
};
