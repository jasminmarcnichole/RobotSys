<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robot_logs', function (Blueprint $table) {
            $table->index(['robot_id', 'created_at'], 'robot_logs_robot_id_created_at_index');
        });

        Schema::table('robot_commands', function (Blueprint $table) {
            $table->index(['robot_id', 'status', 'created_at'], 'robot_commands_robot_id_status_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('robot_logs', function (Blueprint $table) {
            $table->dropIndex('robot_logs_robot_id_created_at_index');
        });

        Schema::table('robot_commands', function (Blueprint $table) {
            $table->dropIndex('robot_commands_robot_id_status_created_at_index');
        });
    }
};
