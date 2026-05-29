<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robot_logs', function (Blueprint $table) {
            $table->integer('plastic_waste_count')->default(0);
            $table->integer('general_waste_count')->default(0);
            $table->string('last_detected_trash_type')->nullable();
            $table->string('plastic_bin_level')->default('Empty');
            $table->string('general_bin_level')->default('Empty');
        });
    }

    public function down(): void
    {
        Schema::table('robot_logs', function (Blueprint $table) {
            $table->dropColumn([
                'plastic_waste_count',
                'general_waste_count',
                'last_detected_trash_type',
                'plastic_bin_level',
                'general_bin_level',
            ]);
        });
    }
};
