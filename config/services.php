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
        'vision_default' => 'efficientdet_lite2',
        'vision_models' => [
            'waste_primary_20261026' => [
                'name' => 'RobotSys Waste (Experimental)',
                'path' => 'models/waste-primary-62dc4baca9bd.tflite',
                'input_size' => 256,
                'sha256' => '62dc4baca9bd716b1309d9418fdc305f08cc6c897274a6aa19748ceb6dd94b9f',
                'labels' => ['plastic_bottle', 'other_plastic', 'plastic_wrapper', 'paper', 'metal_can', 'aluminum_can'],
                'experimental' => true,
                'warning' => 'Experimental waste model: validation is below deployment criteria. Plastic bottles, other plastics, paper, and drink/food cans only. Leaves, wood, and rocks are untrained. Human review required.',
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
