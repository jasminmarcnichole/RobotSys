<?php

return [

    'robot' => [
        'onboard' => [
            'device_id' => env('ESP32_VISION_DEVICE_ID', 'CAM-S3-001'),
            'model' => 'espressif/coco_detect@0.4.2:yolo11n_320_s8_v1_s3',
            'stale_seconds' => 8,
            'retained_frames' => 120,
        ],
        'camera_snapshot_url' => env('ESP32_CAMERA_SNAPSHOT_URL'),
        'camera_stream_url' => env('ESP32_CAMERA_STREAM_URL'),
        'yolo_infer_url' => env('ROBOT_YOLO_INFER_URL', 'http://127.0.0.1:8765'),
        'yolo_weights' => env(
            'ROBOT_YOLO_WEIGHTS',
            storage_path('app/private/waste-training/yolo-experiment-20261110/run-calibrated/weights/best.pt')
        ),
        'vision_default' => env('ROBOT_VISION_MODEL', 'waste_primary_20261026'),
        'vision_models' => [
            'waste_primary_20261026' => [
                'name' => 'RobotSys Waste (7-Class)',
                'path' => 'models/waste-primary-62dc4baca9bd.tflite',
                'input_size' => 256,
                'sha256' => 'b93f380f346d88acdfe30ce86296fdb99b00dd4296b8a9ac37820d8e23217176',
                'labels' => ['plastic_bottle', 'other_plastic', 'plastic_wrapper', 'paper', 'metal_can', 'aluminum_can'],
                'backend_infer' => true,
                'experimental' => false,
                'warning' => 'Trained on 6 waste classes (plastic bottle, other plastic, plastic wrapper, paper, metal can, aluminum can). Leaves, wood, and rocks are untrained. Human review required.',
                'trained_at' => '2026-11-01T13:32:21Z',
                'val_coco_ap' => 0.194,
                'dataset' => 'taco-realdirt-hitl-can-reviewed-20261004',
                'dataset_images' => 390,
            ],
            'efficientdet_lite2' => [
                'name' => 'EfficientDet Lite2',
                'path' => 'models/efficientdet-lite2.tflite',
                'input_size' => 448,
            ],
            'efficientdet_lite0' => [
                'name' => 'EfficientDet Lite0',
                'path' => 'models/efficientdet-lite0.tflite',
                'input_size' => 320,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
