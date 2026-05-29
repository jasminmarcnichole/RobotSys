<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robot_logs', function (Blueprint $table) {
            $table->string('detection_source')->nullable()->after('last_detected_trash_type');
            $table->string('classification_model')->nullable()->after('detection_source');
            $table->string('detected_object_label')->nullable()->after('classification_model');
            $table->decimal('detection_confidence', 5, 2)->nullable()->after('detected_object_label');
            $table->json('detection_bbox')->nullable()->after('detection_confidence');
            $table->string('camera_snapshot_path')->nullable()->after('detection_bbox');
        });
    }

    public function down(): void
    {
        Schema::table('robot_logs', function (Blueprint $table) {
            $table->dropColumn([
                'detection_source',
                'classification_model',
                'detected_object_label',
                'detection_confidence',
                'detection_bbox',
                'camera_snapshot_path',
            ]);
        });
    }
};
