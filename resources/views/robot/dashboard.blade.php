@php
    $latestStatus = $latestLog?->movement_status ?? 'No Data';
    $batteryLevel = (float) ($latestLog?->battery_level ?? 0);
    $successfulPickups = (int) ($latestLog?->successful_pickup_count ?? 0);
    $failedPickups = (int) ($latestLog?->failed_pickup_count ?? 0);
    $totalPickups = $successfulPickups + $failedPickups;
    $successRate = $totalPickups > 0 ? (int) round(($successfulPickups / $totalPickups) * 100) : 0;
    $obstacleCount = (int) ($latestLog?->obstacle_count ?? 0);
    $plasticWasteCount = (int) ($latestLog?->plastic_waste_count ?? 0);
    $generalWasteCount = (int) ($latestLog?->general_waste_count ?? 0);
    $totalWasteCount = $plasticWasteCount + $generalWasteCount;
    $operatingDuration = (int) ($latestLog?->operating_duration ?? 0);
    $formattedOperatingDuration = $operatingDuration >= 60
        ? floor($operatingDuration / 60).'m '.($operatingDuration % 60).'s'
        : $operatingDuration.'s';
    $latestDetectionConfidence = (float) (($latestDetection ?? null)?->detection_confidence ?? 0);
    $detectionConfidence = (int) round(min(100, max(0, $latestDetectionConfidence)));
    $loadCapacityLimit = 20;
    $loadCapacityUsage = (int) min(100, round(($totalWasteCount / $loadCapacityLimit) * 100));
    $wirelessStatus = $latestLog ? 'Telemetry received' : 'Awaiting robot data';
    $lastUpdated = $latestLog?->created_at?->format('M d, Y h:i A') ?? 'Waiting for first log';
    $statusTone = match (strtolower($latestStatus)) {
        'moving', 'running', 'active', 'started', 'start', 'collecting', 'detecting', 'camera detection' => 'success',
        'stopped', 'stop', 'emergency stop' => 'danger',
        'idle', 'reset', 'paused' => 'warning',
        default => 'secondary',
    };
    $statusToneClass = match ($statusTone) {
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'danger' => 'bg-red-50 text-red-700 ring-red-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-zinc-100 text-zinc-600 ring-zinc-200',
    };
    $activePageTitle = $dashboardPages[$activePage]['label'] ?? 'Dashboard';
    $visionModels = collect(config('services.robot.vision_models'))->map(fn ($model) => [
        'name' => $model['name'],
        'url' => asset($model['path']),
        'inputSize' => $model['input_size'],
        'experimental' => $model['experimental'] ?? false,
        'warning' => $model['warning'] ?? '',
    ])->all();
    $defaultVisionModel = config('services.robot.vision_default');
    if (!is_string($defaultVisionModel) || !array_key_exists($defaultVisionModel, $visionModels)) {
        $defaultVisionModel = 'efficientdet_lite2';
    }
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $activePageTitle }} | RobotSys Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('auth.partials.app-meta')
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <script>
        window.robotDashboard = {
            urls: {
                latest: @json(route('dashboard.robot.latest')),
                command: @json(route($activePage === 'control' ? 'dashboard.robot.control' : 'dashboard.robot.command')),
                commands: @json(route('dashboard.robot.commands')),
                log: @json(route('dashboard.camera.detections')),
                detections: @json(route('dashboard.camera.history')),
                insights: @json(route('dashboard.robot.insights')),
                cameraSnapshot: @json(config('services.robot.camera_snapshot_url') ? route('dashboard.camera.snapshot') : null),
                cameraStream: @json(config('services.robot.camera_stream_url')),
            },
            vision: {
                defaultModel: @json($defaultVisionModel),
                models: @json($visionModels),
            },
            robotId: @json($robotId),
            controlApp: @json($activePage === 'control'),
            loadCapacityLimit: @json($loadCapacityLimit),
            latestLog: @json($latestLog),
            latestDetection: @json($latestDetection ?? null),
            insights: @json($workspaceInsights),
        };
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-50 font-sans text-zinc-950 antialiased">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        @include('robot.partials.topbar')

        @include('auth.partials.app-install')

        <main id="main-content" tabindex="-1" class="app-main">
            @include('robot.partials.status-strip')
            @include('robot.partials.workspace-health')

            @includeIf('robot.partials.'.$activePage)

            <footer class="mt-10 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 pt-4 text-xs text-zinc-500">
                <a href="{{ route('manual') }}" class="portal-footer-link">RobotSys operations <span aria-hidden="true">&middot;</span> Operator manual</a>
                <span>{{ $robotId }}</span>
            </footer>
        </main>
        @include('robot.partials.workspace-search')
    </div>
</body>
</html>
