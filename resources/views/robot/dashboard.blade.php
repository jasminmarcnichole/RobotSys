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
    $latestDetectionConfidence = (float) ($latestLog?->detection_confidence ?? 0);
    $detectionAccuracy = (int) round(min(100, max(0, $latestDetectionConfidence)));
    $navigationEfficiency = $operatingDuration > 0 || $obstacleCount > 0
        ? (int) max(0, min(100, 100 - ($obstacleCount * 7)))
        : 0;
    $loadCapacityLimit = 20;
    $loadCapacityUsage = (int) min(100, round(($totalWasteCount / $loadCapacityLimit) * 100));
    $collectionReadiness = $totalPickups === 0
        ? 'Awaiting pickups'
        : ($successRate >= 70 ? 'Collection stable' : 'Needs tuning');
    $navigationMode = match (strtolower($latestStatus)) {
        'moving', 'running', 'active', 'started', 'start', 'collecting', 'detecting', 'camera detection' => 'Path tracking',
        'stopped', 'stop', 'emergency stop' => 'Safety stop',
        'idle', 'reset', 'paused' => 'Standby',
        default => 'Awaiting telemetry',
    };
    $wirelessStatus = $latestLog ? 'Telemetry active' : 'Awaiting robot data';
    $lastUpdated = $latestLog?->created_at?->format('M d, Y h:i A') ?? 'Waiting for first log';
    $statusTone = match (strtolower($latestStatus)) {
        'moving', 'running', 'active', 'started', 'start', 'collecting', 'detecting', 'camera detection' => 'success',
        'stopped', 'stop', 'emergency stop' => 'danger',
        'idle', 'reset', 'paused' => 'warning',
        default => 'secondary',
    };
    $activePageTitle = $dashboardPages[$activePage]['label'] ?? 'Dashboard';
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $activePageTitle }} | RobotSys Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --surface: #ffffff;
            --surface-muted: #f5f7fb;
            --ink: #172033;
            --muted: #667085;
            --line: #e6eaf0;
            --brand: #159a6a;
            --brand-strong: #08734c;
            --accent: #2563eb;
            --warning: #b7791f;
            --danger: #c2413b;
            --shadow: 0 18px 50px rgba(23, 32, 51, 0.08);
        }

        body {
            min-height: 100vh;
            background:
                linear-gradient(180deg, rgba(21, 154, 106, 0.08), transparent 280px),
                var(--surface-muted);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .app-shell {
            min-height: 100vh;
        }

        .main-panel {
            min-height: 100vh;
            min-width: 0;
        }

        .topbar {
            background: rgba(255, 255, 255, 0.9);
            border-bottom: 1px solid rgba(230, 234, 240, 0.9);
            backdrop-filter: blur(18px);
        }

        .topbar-brand {
            align-items: center;
            color: var(--ink);
            display: inline-flex;
            gap: 0.75rem;
            text-decoration: none;
        }

        .topbar-brand:hover {
            color: var(--ink);
        }

        .page-nav {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            padding-bottom: 0.15rem;
        }

        .page-nav a {
            align-items: center;
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: #475467;
            display: inline-flex;
            flex: 0 0 auto;
            gap: 0.65rem;
            min-height: 44px;
            padding: 0.55rem 0.75rem;
            text-decoration: none;
            transition: background 160ms ease, border-color 160ms ease, color 160ms ease;
        }

        .page-nav a:hover,
        .page-nav a.active {
            background: #101828;
            border-color: #101828;
            color: #ffffff;
        }

        .page-nav-code {
            align-items: center;
            background: #eef2f7;
            border-radius: 6px;
            color: #475467;
            display: inline-flex;
            flex: 0 0 30px;
            font-size: 0.72rem;
            font-weight: 800;
            height: 30px;
            justify-content: center;
        }

        .page-nav a:hover .page-nav-code,
        .page-nav a.active .page-nav-code {
            background: rgba(255, 255, 255, 0.12);
            color: rgba(255, 255, 255, 0.86);
        }

        .page-nav-description {
            display: block;
            font-size: 0.75rem;
            line-height: 1.1;
            opacity: 0.72;
        }

        .brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--brand), var(--accent));
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .dashboard-header {
            background: linear-gradient(135deg, #122035, #143a45 55%, #0f5d45);
            border-radius: 8px;
            color: #ffffff;
            box-shadow: var(--shadow);
            overflow: hidden;
            position: relative;
        }

        .dashboard-header::after {
            content: "";
            position: absolute;
            inset: auto -8% -80% auto;
            width: 320px;
            height: 320px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 50%;
        }

        .dashboard-header > * {
            position: relative;
            z-index: 1;
        }

        .eyebrow {
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        .subtle {
            color: var(--muted);
        }

        .panel,
        .metric-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 8px 28px rgba(23, 32, 51, 0.06);
        }

        .metric-card {
            min-height: 156px;
            transition: transform 160ms ease, box-shadow 160ms ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .metric-label {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        .metric-value {
            color: var(--ink);
            font-size: clamp(1.65rem, 3vw, 2.45rem);
            font-weight: 800;
            line-height: 1;
            letter-spacing: 0;
        }

        .metric-suffix {
            color: var(--muted);
            font-size: 1rem;
            font-weight: 700;
        }

        .status-pill,
        .bin-pill {
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.8rem;
            font-weight: 800;
            line-height: 1;
            padding: 0.48rem 0.7rem;
            white-space: nowrap;
        }

        .status-dot {
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 999px;
            background: currentColor;
        }

        .status-success {
            background: #dcfce7;
            color: #157347;
        }

        .status-danger {
            background: #fee2e2;
            color: #b42318;
        }

        .status-warning {
            background: #fef3c7;
            color: #9a6700;
        }

        .status-secondary {
            background: #edf2f7;
            color: #475467;
        }

        .progress {
            height: 0.55rem;
            background: #edf2f7;
            border-radius: 999px;
        }

        .battery-bar {
            background: linear-gradient(90deg, var(--brand), #55c2a0);
        }

        .battery-bar.is-warning {
            background: linear-gradient(90deg, #d99a2b, #f2c94c);
        }

        .battery-bar.is-danger {
            background: linear-gradient(90deg, var(--danger), #fb7185);
        }

        .command-button {
            min-height: 46px;
            border-radius: 8px;
            font-weight: 800;
        }

        .command-button:disabled {
            cursor: wait;
        }

        .quick-stat {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0.9rem;
            background: #fbfcfe;
        }

        .quick-stat-value {
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1;
        }

        .module-card {
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 8px 28px rgba(23, 32, 51, 0.05);
            height: 100%;
            padding: 1rem;
        }

        .module-icon {
            align-items: center;
            background: #eef7f4;
            border-radius: 8px;
            color: var(--brand-strong);
            display: inline-flex;
            font-size: 0.82rem;
            font-weight: 800;
            height: 40px;
            justify-content: center;
            width: 40px;
        }

        .module-title {
            font-size: 0.98rem;
            font-weight: 800;
            margin: 0;
        }

        .module-description {
            color: var(--muted);
            font-size: 0.88rem;
            margin: 0;
        }

        .objective-list {
            display: grid;
            gap: 0.7rem;
        }

        .objective-item {
            align-items: flex-start;
            display: flex;
            gap: 0.65rem;
        }

        .objective-check {
            align-items: center;
            background: #dcfce7;
            border-radius: 999px;
            color: #157347;
            display: inline-flex;
            flex: 0 0 1.35rem;
            font-size: 0.76rem;
            font-weight: 900;
            height: 1.35rem;
            justify-content: center;
            margin-top: 0.05rem;
            width: 1.35rem;
        }

        .evaluation-card {
            background: #fbfcfe;
            border: 1px solid var(--line);
            border-radius: 8px;
            height: 100%;
            padding: 1rem;
        }

        .evaluation-value {
            color: var(--ink);
            font-size: 1.65rem;
            font-weight: 850;
            line-height: 1;
        }

        .evaluation-progress {
            background: #e8edf5;
            border-radius: 999px;
            height: 0.55rem;
            overflow: hidden;
        }

        .evaluation-progress span {
            background: linear-gradient(90deg, var(--brand), var(--accent));
            display: block;
            height: 100%;
            transition: width 180ms ease;
            width: 0%;
        }

        .camera-stage {
            aspect-ratio: 16 / 9;
            background: #0f172a;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
        }

        .camera-stage video,
        .camera-stage canvas {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        #detectionOverlay {
            position: absolute;
            inset: 0;
            pointer-events: none;
            width: 100%;
            height: 100%;
        }

        .camera-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            text-align: center;
            color: rgba(255, 255, 255, 0.74);
            background:
                linear-gradient(135deg, rgba(37, 99, 235, 0.2), rgba(21, 154, 106, 0.18)),
                #101828;
        }

        .camera-placeholder.is-hidden {
            display: none;
        }

        .camera-readout {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fbfcfe;
            padding: 1rem;
        }

        .confidence-meter {
            height: 0.65rem;
            border-radius: 999px;
            background: #e8edf5;
            overflow: hidden;
        }

        .confidence-meter span {
            display: block;
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--accent), var(--brand));
            transition: width 180ms ease;
        }

        .detection-history {
            max-height: 156px;
            overflow: auto;
        }

        .detection-item {
            border-top: 1px solid var(--line);
            color: #475467;
            font-size: 0.88rem;
            padding: 0.7rem 0;
        }

        .snapshot-thumb {
            width: 68px;
            height: 44px;
            border-radius: 6px;
            object-fit: cover;
        }

        .evidence-preview {
            border: 1px solid var(--line);
            border-radius: 8px;
            display: none;
            width: 100%;
        }

        .evidence-preview.is-visible {
            display: block;
        }

        .table-shell {
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: hidden;
        }

        .robot-table {
            min-width: 1320px;
        }

        .robot-table thead th {
            background: #f8fafc;
            color: #475467;
            border-bottom: 1px solid var(--line);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0;
            white-space: nowrap;
        }

        .robot-table tbody td {
            color: #344054;
            font-size: 0.9rem;
            vertical-align: middle;
        }

        .empty-state {
            background: #fbfcfe;
            border: 1px dashed #c9d2df;
            border-radius: 8px;
            color: var(--muted);
        }

        .sync-chip {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 999px;
            color: rgba(255, 255, 255, 0.86);
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.82rem;
            font-weight: 700;
            padding: 0.55rem 0.75rem;
        }

        .toast-line {
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            padding: 0.75rem 0.9rem;
        }

        @media (max-width: 575.98px) {
            .dashboard-header {
                border-radius: 0;
                margin-left: -1rem;
                margin-right: -1rem;
            }

            .metric-card {
                min-height: auto;
            }
        }

        @media (max-width: 991.98px) {
            .page-nav a {
                min-width: 178px;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <div class="main-panel">
            <nav class="topbar sticky-top">
                <div class="container-fluid px-3 px-lg-5 py-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <a href="{{ route('robot.dashboard') }}" class="topbar-brand">
                            <span class="brand-mark">RS</span>
                            <span>
                                <span class="d-block fw-bold lh-1">RobotSys</span>
                                <span class="d-block small subtle">{{ $activePageTitle }}</span>
                            </span>
                        </a>

                        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
                            <span class="status-pill status-{{ $statusTone }}" id="movement_status">
                                <span class="status-dot"></span>
                                <span data-status-label>{{ $latestStatus }}</span>
                            </span>
                            <span class="badge text-bg-light border fw-semibold px-3 py-2">
                                {{ $latestLog->robot_id ?? 'ROBOT-001' }}
                            </span>
                            <form method="POST" action="{{ route('admin.logout') }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary command-button">
                                    Logout
                                </button>
                            </form>
                        </div>
                    </div>

                    <nav class="page-nav mt-3" aria-label="Dashboard pages">
                        @foreach ($dashboardPages as $pageKey => $page)
                            <a href="{{ route($page['route']) }}" class="{{ $activePage === $pageKey ? 'active' : '' }}" @if ($activePage === $pageKey) aria-current="page" @endif>
                                <span class="page-nav-code">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <span>
                                    <span class="d-block fw-bold lh-sm">{{ $page['label'] }}</span>
                                    <span class="page-nav-description">{{ $page['description'] }}</span>
                                </span>
                            </a>
                        @endforeach
                    </nav>
                </div>
            </nav>

            <main class="container-fluid px-3 px-lg-5 py-4">
                @if ($activePage === 'overview')
            <section id="overview" class="dashboard-header p-4 p-lg-5 mb-4">
                <div class="row g-4 align-items-end">
                    <div class="col-lg-7">
                        <div class="eyebrow mb-2">Live operations console</div>
                        <h1 class="display-6 fw-bold mb-3">Autonomous Trash Collecting Robot Dashboard</h1>
                        <p class="mb-0 text-white-50">
                            Battery, pickup performance, obstacle events, bin levels, and robot commands in one control surface.
                        </p>
                    </div>
                    <div class="col-lg-5">
                        <div class="d-flex flex-wrap justify-content-lg-end gap-2">
                            <span class="sync-chip">
                                <span class="status-dot"></span>
                                <span id="connection_status">Live polling</span>
                            </span>
                            <span class="sync-chip">
                                Last update: <span id="last_updated">{{ $lastUpdated }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-3 g-xl-4 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <article class="metric-card p-3 p-lg-4 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                            <span class="metric-label">Battery Level</span>
                            <span class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle">Power</span>
                        </div>
                        <div class="d-flex align-items-baseline gap-1 mb-3">
                            <span id="battery_level" class="metric-value">{{ number_format($batteryLevel, 0) }}</span>
                            <span class="metric-suffix">%</span>
                        </div>
                        <div class="progress" role="progressbar" aria-label="Battery level">
                            <div id="battery_progress" class="progress-bar battery-bar {{ $batteryLevel <= 20 ? 'is-danger' : ($batteryLevel <= 45 ? 'is-warning' : '') }}" style="width: {{ min(100, max(0, $batteryLevel)) }}%"></div>
                        </div>
                    </article>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <article class="metric-card p-3 p-lg-4 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                            <span class="metric-label">Trash Detected</span>
                            <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle">Vision</span>
                        </div>
                        <div id="detected_trash_count" class="metric-value mb-3">{{ $latestLog->detected_trash_count ?? 0 }}</div>
                        <div class="small subtle">Last type: <span id="last_detected_trash_type" class="fw-bold text-dark">{{ $latestLog->last_detected_trash_type ?? 'No Data' }}</span></div>
                    </article>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <article class="metric-card p-3 p-lg-4 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                            <span class="metric-label">Pickup Success</span>
                            <span class="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle">Quality</span>
                        </div>
                        <div class="d-flex align-items-baseline gap-1 mb-3">
                            <span id="success_rate" class="metric-value">{{ $successRate }}</span>
                            <span class="metric-suffix">%</span>
                        </div>
                        <div class="small subtle">
                            <span id="successful_pickup_count" class="fw-bold text-success">{{ $successfulPickups }}</span> successful /
                            <span id="failed_pickup_count" class="fw-bold text-danger">{{ $failedPickups }}</span> failed
                        </div>
                    </article>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <article class="metric-card p-3 p-lg-4 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                            <span class="metric-label">Obstacles</span>
                            <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle">Safety</span>
                        </div>
                        <div id="obstacle_count" class="metric-value mb-3">{{ $latestLog->obstacle_count ?? 0 }}</div>
                        <div class="small subtle">Runtime: <span id="operating_duration" class="fw-bold text-dark">{{ $formattedOperatingDuration }}</span></div>
                    </article>
                </div>
            </section>
                @endif

                @if ($activePage === 'capabilities')
            <section id="capabilities" class="row g-4 mb-4">
                <div class="col-xl-8">
                    <div class="panel p-3 p-lg-4 h-100">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <h2 class="h5 fw-bold mb-1">System Capability Map</h2>
                                <p class="subtle mb-0">Dashboard modules aligned with the autonomous trash collecting robot objectives.</p>
                            </div>
                            <span class="badge text-bg-light border px-3 py-2">Controlled environment</span>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <article class="module-card">
                                    <div class="d-flex align-items-start gap-3 mb-3">
                                        <span class="module-icon">CV</span>
                                        <div>
                                            <h3 class="module-title">Camera Trash Detection</h3>
                                            <p class="module-description">Live camera classification for lightweight waste targets.</p>
                                        </div>
                                    </div>
                                    <div id="module_detection_meta" class="small fw-semibold text-dark">
                                        {{ $latestLog->detected_trash_count ?? 0 }} detected | {{ $latestLog->last_detected_trash_type ?? 'No classification' }}
                                    </div>
                                </article>
                            </div>

                            <div class="col-md-6">
                                <article class="module-card">
                                    <div class="d-flex align-items-start gap-3 mb-3">
                                        <span class="module-icon">NAV</span>
                                        <div>
                                            <h3 class="module-title">Navigation & Avoidance</h3>
                                            <p class="module-description">Movement status, runtime, and obstacle events for path planning review.</p>
                                        </div>
                                    </div>
                                    <div class="small fw-semibold text-dark">
                                        <span id="module_navigation_status">{{ $navigationMode }}</span> |
                                        <span id="module_navigation_meta">{{ $obstacleCount }} obstacles, {{ $formattedOperatingDuration }}</span>
                                    </div>
                                </article>
                            </div>

                            <div class="col-md-6">
                                <article class="module-card">
                                    <div class="d-flex align-items-start gap-3 mb-3">
                                        <span class="module-icon">ARM</span>
                                        <div>
                                            <h3 class="module-title">Robotic Arm Pickup</h3>
                                            <p class="module-description">Grip and lift success tracking for lightweight waste collection.</p>
                                        </div>
                                    </div>
                                    <div class="small fw-semibold text-dark">
                                        <span id="module_arm_status">{{ $collectionReadiness }}</span> |
                                        <span id="module_arm_meta">{{ $successfulPickups }} success, {{ $failedPickups }} failed</span>
                                    </div>
                                </article>
                            </div>

                            <div class="col-md-6">
                                <article class="module-card">
                                    <div class="d-flex align-items-start gap-3 mb-3">
                                        <span class="module-icon">RF</span>
                                        <div>
                                            <h3 class="module-title">Wireless Telemetry</h3>
                                            <p class="module-description">Real-time monitoring, command dispatch, logging, and status reporting.</p>
                                        </div>
                                    </div>
                                    <div class="small fw-semibold text-dark">
                                        <span id="module_wireless_status">{{ $wirelessStatus }}</span> |
                                        <span id="module_wireless_meta">{{ $lastUpdated }}</span>
                                    </div>
                                </article>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="panel p-3 p-lg-4 h-100">
                        <div class="mb-3">
                            <h2 class="h5 fw-bold mb-1">Project Objective Coverage</h2>
                            <p class="subtle mb-0">What this system currently represents in software.</p>
                        </div>

                        <div class="objective-list">
                            <div class="objective-item">
                                <span class="objective-check">✓</span>
                                <div>
                                    <div class="fw-bold">Detect and classify trash</div>
                                    <div class="small subtle">Live camera detection and logged classification metadata.</div>
                                </div>
                            </div>
                            <div class="objective-item">
                                <span class="objective-check">✓</span>
                                <div>
                                    <div class="fw-bold">Navigate with obstacle awareness</div>
                                    <div class="small subtle">Movement, runtime, and obstacle telemetry are tracked for route review.</div>
                                </div>
                            </div>
                            <div class="objective-item">
                                <span class="objective-check">✓</span>
                                <div>
                                    <div class="fw-bold">Collect lightweight waste</div>
                                    <div class="small subtle">Pickup success and failure counts represent arm/gripper performance.</div>
                                </div>
                            </div>
                            <div class="objective-item">
                                <span class="objective-check">✓</span>
                                <div>
                                    <div class="fw-bold">Monitor wirelessly</div>
                                    <div class="small subtle">API commands, polling, logs, and status reporting support remote monitoring.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
                @endif

                @if ($activePage === 'camera')
            <section id="camera" class="row g-4 mb-4">
                <div class="col-xl-7">
                    <div class="panel p-3 p-lg-4 h-100">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <h2 class="h5 fw-bold mb-1">Camera Detection</h2>
                                <p class="subtle mb-0">Use the laptop camera to classify visible trash in real time.</p>
                            </div>
                            <span id="camera_status" class="status-pill status-secondary">
                                <span class="status-dot"></span>
                                Camera off
                            </span>
                        </div>

                        <div class="camera-stage mb-3">
                            <video id="cameraFeed" autoplay playsinline muted></video>
                            <canvas id="cameraSnapshot" class="d-none"></canvas>
                            <canvas id="detectionOverlay" class="d-none"></canvas>
                            <div id="cameraPlaceholder" class="camera-placeholder">
                                <div>
                                    <div class="fw-bold mb-1">Laptop camera preview</div>
                                    <div class="small">Start the camera and live detection will run automatically.</div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" id="startCameraButton" class="btn btn-primary command-button">
                                Use Laptop Camera
                            </button>
                            <button type="button" id="liveDetectionButton" class="btn btn-outline-primary command-button" disabled>
                                Live Detection
                            </button>
                            <button type="button" id="stopCameraButton" class="btn btn-outline-secondary command-button" disabled>
                                Stop Camera
                            </button>
                        </div>

                        <div id="cameraMessage" class="toast-line bg-light text-secondary mt-3" role="status" aria-live="polite">
                            Live object detection will start when the camera is opened.
                        </div>
                    </div>
                </div>

                <div class="col-xl-5">
                    <div class="panel p-3 p-lg-4 h-100">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <h2 class="h5 fw-bold mb-1">Detection Result</h2>
                                <p class="subtle mb-0">Review the current live estimate before saving it to telemetry.</p>
                            </div>
                            <span class="badge text-bg-light border px-3 py-2">Local estimate</span>
                        </div>

                        <div class="camera-readout mb-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                <span class="metric-label">ML Model</span>
                                <span id="cameraModelLabel" class="badge text-bg-light border px-3 py-2">Not loaded</span>
                            </div>

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                <span class="metric-label">Detected Object</span>
                                <span id="cameraObjectLabel" class="fw-bold text-dark">Waiting for live detection</span>
                            </div>

                            <div class="metric-label mb-2">Detected Trash Type</div>
                            <select id="cameraTrashType" class="form-select fw-semibold">
                                <option value="Plastic Waste">Plastic Waste</option>
                                <option value="General Waste">General Waste</option>
                                <option value="Paper Waste">Paper Waste</option>
                                <option value="Metal Waste">Metal Waste</option>
                                <option value="Glass Waste">Glass Waste</option>
                                <option value="Organic Waste">Organic Waste</option>
                            </select>

                            <div class="d-flex justify-content-between align-items-center gap-3 mt-3 mb-2">
                                <span class="metric-label">Confidence</span>
                                <span id="cameraConfidenceLabel" class="fw-bold">0%</span>
                            </div>
                            <div class="confidence-meter" aria-label="Detection confidence">
                                <span id="cameraConfidenceBar"></span>
                            </div>
                        </div>

                        <img id="cameraEvidencePreview" class="evidence-preview mb-3" alt="Captured camera detection evidence">

                        <button type="button" id="saveCameraDetectionButton" class="btn btn-success command-button w-100" disabled>
                            Save Detection to Logs
                        </button>

                        <div class="detection-history mt-3" id="cameraDetectionHistory">
                            <div class="detection-item text-muted">No camera detections saved yet.</div>
                        </div>
                    </div>
                </div>
            </section>
                @endif

                @if ($activePage === 'waste')
            <section id="waste" class="row g-4 mb-4">
                <div class="col-xl-8">
                    <div class="panel p-3 p-lg-4 h-100">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <h2 class="h5 fw-bold mb-1">Waste Sorting</h2>
                                <p class="subtle mb-0">Container load and classification summary</p>
                            </div>
                            <span class="badge text-bg-light border px-3 py-2">
                                Total waste: <span id="total_waste_count">{{ $totalWasteCount }}</span>
                            </span>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="quick-stat h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                        <span class="metric-label">Plastic Waste</span>
                                        <span class="bin-pill status-success" id="plastic_bin_level">{{ $latestLog->plastic_bin_level ?? 'Empty' }}</span>
                                    </div>
                                    <div id="plastic_waste_count" class="quick-stat-value text-success">{{ $plasticWasteCount }}</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="quick-stat h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                        <span class="metric-label">General Waste</span>
                                        <span class="bin-pill status-secondary" id="general_bin_level">{{ $latestLog->general_bin_level ?? 'Empty' }}</span>
                                    </div>
                                    <div id="general_waste_count" class="quick-stat-value text-primary">{{ $generalWasteCount }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
                @endif

                @if ($activePage === 'commands')
            <section id="commands" class="row g-4 mb-4">
                <div class="col-xl-5">
                    <div class="panel p-3 p-lg-4 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <h2 class="h5 fw-bold mb-1">Command Panel</h2>
                                <p class="subtle mb-0">ROBOT-001</p>
                            </div>
                            <span class="badge text-bg-light border px-3 py-2">Pending API</span>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-success command-button" data-command="START">
                                Start Autonomous Operation
                            </button>
                            <button type="button" class="btn btn-danger command-button" data-command="STOP">
                                Emergency Stop
                            </button>
                            <button type="button" class="btn btn-warning command-button" data-command="RESET">
                                Reset Robot
                            </button>
                        </div>

                        <div id="commandMessage" class="toast-line bg-light text-secondary mt-3" role="status" aria-live="polite">
                            Ready to send a command.
                        </div>
                    </div>
                </div>
            </section>
                @endif

                @if ($activePage === 'evaluation')
            <section id="evaluation" class="panel p-3 p-lg-4 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="h5 fw-bold mb-1">Performance Evaluation</h2>
                        <p class="subtle mb-0">Operational indicators for detection accuracy, navigation efficiency, collection success, battery endurance, and load capacity.</p>
                    </div>
                    <span class="badge text-bg-light border px-3 py-2">Latest log based</span>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6 col-xl">
                        <article class="evaluation-card">
                            <div class="metric-label mb-3">Detection Accuracy</div>
                            <div class="d-flex align-items-baseline gap-1 mb-3">
                                <span id="evaluation_detection_accuracy" class="evaluation-value">{{ $detectionAccuracy }}</span>
                                <span class="metric-suffix">%</span>
                            </div>
                            <div class="evaluation-progress" aria-label="Detection accuracy">
                                <span id="evaluation_detection_accuracy_bar" style="width: {{ $detectionAccuracy }}%"></span>
                            </div>
                            <div id="evaluation_detection_note" class="small subtle mt-2">Latest model confidence</div>
                        </article>
                    </div>

                    <div class="col-sm-6 col-xl">
                        <article class="evaluation-card">
                            <div class="metric-label mb-3">Navigation Efficiency</div>
                            <div class="d-flex align-items-baseline gap-1 mb-3">
                                <span id="evaluation_navigation_efficiency" class="evaluation-value">{{ $navigationEfficiency }}</span>
                                <span class="metric-suffix">%</span>
                            </div>
                            <div class="evaluation-progress" aria-label="Navigation efficiency">
                                <span id="evaluation_navigation_efficiency_bar" style="width: {{ $navigationEfficiency }}%"></span>
                            </div>
                            <div id="evaluation_navigation_note" class="small subtle mt-2">{{ $obstacleCount }} obstacle events</div>
                        </article>
                    </div>

                    <div class="col-sm-6 col-xl">
                        <article class="evaluation-card">
                            <div class="metric-label mb-3">Collection Success</div>
                            <div class="d-flex align-items-baseline gap-1 mb-3">
                                <span id="evaluation_collection_success" class="evaluation-value">{{ $successRate }}</span>
                                <span class="metric-suffix">%</span>
                            </div>
                            <div class="evaluation-progress" aria-label="Collection success">
                                <span id="evaluation_collection_success_bar" style="width: {{ $successRate }}%"></span>
                            </div>
                            <div id="evaluation_collection_note" class="small subtle mt-2">{{ $totalPickups }} pickup attempts</div>
                        </article>
                    </div>

                    <div class="col-sm-6 col-xl">
                        <article class="evaluation-card">
                            <div class="metric-label mb-3">Battery Endurance</div>
                            <div class="d-flex align-items-baseline gap-1 mb-3">
                                <span id="evaluation_battery_endurance" class="evaluation-value">{{ number_format($batteryLevel, 0) }}</span>
                                <span class="metric-suffix">%</span>
                            </div>
                            <div class="evaluation-progress" aria-label="Battery endurance">
                                <span id="evaluation_battery_endurance_bar" style="width: {{ min(100, max(0, $batteryLevel)) }}%"></span>
                            </div>
                            <div id="evaluation_battery_note" class="small subtle mt-2">Runtime {{ $formattedOperatingDuration }}</div>
                        </article>
                    </div>

                    <div class="col-sm-6 col-xl">
                        <article class="evaluation-card">
                            <div class="metric-label mb-3">Load Capacity</div>
                            <div class="d-flex align-items-baseline gap-1 mb-3">
                                <span id="evaluation_load_capacity" class="evaluation-value">{{ $loadCapacityUsage }}</span>
                                <span class="metric-suffix">%</span>
                            </div>
                            <div class="evaluation-progress" aria-label="Load capacity">
                                <span id="evaluation_load_capacity_bar" style="width: {{ $loadCapacityUsage }}%"></span>
                            </div>
                            <div id="evaluation_load_note" class="small subtle mt-2">{{ $totalWasteCount }} / {{ $loadCapacityLimit }} lightweight items</div>
                        </article>
                    </div>
                </div>
            </section>
                @endif

                @if ($activePage === 'logs')
            <section id="logs" class="panel p-3 p-lg-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h2 class="h5 fw-bold mb-1">Recent Activity Logs</h2>
                        <p class="subtle mb-0">Latest 10 telemetry records</p>
                    </div>
                    <span class="badge text-bg-light border px-3 py-2">{{ $logs->count() }} records</span>
                </div>

                @if ($logs->isEmpty())
                    <div class="empty-state p-4 text-center">
                        No robot logs yet.
                    </div>
                @else
                    <div class="table-shell table-responsive">
                        <table class="table table-hover robot-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Robot ID</th>
                                    <th>Status</th>
                                    <th class="text-end">Battery</th>
                                    <th class="text-end">Detected</th>
                                    <th class="text-end">Success</th>
                                    <th class="text-end">Failed</th>
                                    <th class="text-end">Obstacles</th>
                                    <th class="text-end">Duration</th>
                                    <th class="text-end">Plastic</th>
                                    <th class="text-end">General</th>
                                    <th>Last Type</th>
                                    <th>Detected Object</th>
                                    <th class="text-end">Confidence</th>
                                    <th>Evidence</th>
                                    <th>Plastic Bin</th>
                                    <th>General Bin</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($logs as $log)
                                    @php
                                        $rowStatusTone = match (strtolower($log->movement_status)) {
                                            'moving', 'running', 'active', 'started', 'start', 'collecting', 'detecting', 'camera detection' => 'success',
                                            'stopped', 'stop', 'emergency stop' => 'danger',
                                            'idle', 'reset', 'paused' => 'warning',
                                            default => 'secondary',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="text-nowrap">{{ $log->created_at->format('M d, Y h:i A') }}</td>
                                        <td class="fw-semibold">{{ $log->robot_id }}</td>
                                        <td>
                                            <span class="status-pill status-{{ $rowStatusTone }}">
                                                <span class="status-dot"></span>
                                                {{ $log->movement_status }}
                                            </span>
                                        </td>
                                        <td class="text-end">{{ number_format((float) $log->battery_level, 0) }}%</td>
                                        <td class="text-end">{{ $log->detected_trash_count }}</td>
                                        <td class="text-end text-success fw-semibold">{{ $log->successful_pickup_count }}</td>
                                        <td class="text-end text-danger fw-semibold">{{ $log->failed_pickup_count }}</td>
                                        <td class="text-end">{{ $log->obstacle_count }}</td>
                                        <td class="text-end">{{ $log->operating_duration }}s</td>
                                        <td class="text-end">{{ $log->plastic_waste_count }}</td>
                                        <td class="text-end">{{ $log->general_waste_count }}</td>
                                        <td>{{ $log->last_detected_trash_type ?? '-' }}</td>
                                        <td>{{ $log->detected_object_label ?? '-' }}</td>
                                        <td class="text-end">{{ $log->detection_confidence ? number_format((float) $log->detection_confidence, 0).'%' : '-' }}</td>
                                        <td>
                                            @if ($log->camera_snapshot_url)
                                                <img class="snapshot-thumb" src="{{ $log->camera_snapshot_url }}" alt="Detection snapshot">
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td><span class="bin-pill status-success">{{ $log->plastic_bin_level }}</span></td>
                                        <td><span class="bin-pill status-secondary">{{ $log->general_bin_level }}</span></td>
                                        <td class="text-muted">{{ $log->remarks ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
                @endif
            </main>
        </div>
    </div>

    @if ($activePage === 'camera')
        <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.22.0/dist/tf.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.3/dist/coco-ssd.min.js"></script>
    @endif
    <script>
        const latestRobotUrl = @json(route('api.robot.latest'));
        const commandRobotUrl = @json(route('api.robot.command'));
        const robotLogUrl = @json(route('api.robot.log'));
        let latestRobotData = @json($latestLog);
        let cameraStream = null;
        let lastCameraDetection = null;
        let liveDetectionTimer = null;
        let liveDetectionRunId = 0;
        let isLiveDetecting = false;
        let isSavingCameraDetection = false;
        const liveDetectionInterval = 900;
        const commandButtons = document.querySelectorAll('[data-command]');
        const commandMessage = document.getElementById('commandMessage');
        const cameraFeed = document.getElementById('cameraFeed');
        const cameraSnapshot = document.getElementById('cameraSnapshot');
        const detectionOverlay = document.getElementById('detectionOverlay');
        const cameraPlaceholder = document.getElementById('cameraPlaceholder');
        const cameraStatus = document.getElementById('camera_status');
        const cameraMessage = document.getElementById('cameraMessage');
        const startCameraButton = document.getElementById('startCameraButton');
        const liveDetectionButton = document.getElementById('liveDetectionButton');
        const stopCameraButton = document.getElementById('stopCameraButton');
        const saveCameraDetectionButton = document.getElementById('saveCameraDetectionButton');
        const cameraTrashType = document.getElementById('cameraTrashType');
        const cameraConfidenceLabel = document.getElementById('cameraConfidenceLabel');
        const cameraConfidenceBar = document.getElementById('cameraConfidenceBar');
        const cameraDetectionHistory = document.getElementById('cameraDetectionHistory');
        const cameraModelLabel = document.getElementById('cameraModelLabel');
        const cameraObjectLabel = document.getElementById('cameraObjectLabel');
        const cameraEvidencePreview = document.getElementById('cameraEvidencePreview');
        const cameraModelName = 'TensorFlow.js COCO-SSD 2.2.3';
        const loadCapacityLimit = @json($loadCapacityLimit);
        let objectDetectionModel = null;
        let objectDetectionModelPromise = null;
        const wasteCategoryByObject = {
            bottle: 'Plastic Waste',
            cup: 'Plastic Waste',
            bowl: 'Plastic Waste',
            toothbrush: 'Plastic Waste',
            'wine glass': 'Glass Waste',
            fork: 'Metal Waste',
            knife: 'Metal Waste',
            spoon: 'Metal Waste',
            scissors: 'Metal Waste',
            book: 'Paper Waste',
            banana: 'Organic Waste',
            apple: 'Organic Waste',
            sandwich: 'Organic Waste',
            orange: 'Organic Waste',
            broccoli: 'Organic Waste',
            carrot: 'Organic Waste',
            'hot dog': 'Organic Waste',
            pizza: 'Organic Waste',
            donut: 'Organic Waste',
            cake: 'Organic Waste',
        };

        function setText(id, value, fallback = '0') {
            const element = document.getElementById(id);

            if (element) {
                element.innerText = value ?? fallback;
            }
        }

        function numberValue(value) {
            const normalizedValue = typeof value === 'string' ? value.replace('%', '') : value;
            const parsed = Number(normalizedValue);

            return Number.isFinite(parsed) ? parsed : 0;
        }

        function statusTone(status) {
            const normalizedStatus = String(status ?? '').toLowerCase();

            if (['moving', 'running', 'active', 'started', 'start', 'collecting', 'detecting', 'camera detection'].includes(normalizedStatus)) {
                return 'success';
            }

            if (['stopped', 'stop', 'emergency stop'].includes(normalizedStatus)) {
                return 'danger';
            }

            if (['idle', 'reset', 'paused'].includes(normalizedStatus)) {
                return 'warning';
            }

            return 'secondary';
        }

        function updateStatus(status) {
            const statusElement = document.getElementById('movement_status');
            const labelElement = statusElement?.querySelector('[data-status-label]');

            if (!statusElement || !labelElement) {
                return;
            }

            statusElement.className = `status-pill status-${statusTone(status)}`;
            labelElement.innerText = status ?? 'No Data';
        }

        function updateBattery(level) {
            const batteryLevel = Math.max(0, Math.min(100, numberValue(level)));
            const progress = document.getElementById('battery_progress');

            setText('battery_level', Math.round(batteryLevel));

            if (!progress) {
                return;
            }

            progress.style.width = `${batteryLevel}%`;
            progress.classList.toggle('is-danger', batteryLevel <= 20);
            progress.classList.toggle('is-warning', batteryLevel > 20 && batteryLevel <= 45);
        }

        function updateSuccessRate(successful, failed) {
            const successfulCount = numberValue(successful);
            const failedCount = numberValue(failed);
            const total = successfulCount + failedCount;
            const rate = total > 0 ? Math.round((successfulCount / total) * 100) : 0;

            setText('success_rate', rate);
        }

        function updateWasteTotals(plastic, general) {
            setText('total_waste_count', numberValue(plastic) + numberValue(general));
        }

        function formatDuration(seconds) {
            const totalSeconds = numberValue(seconds);
            const minutes = Math.floor(totalSeconds / 60);
            const remainingSeconds = totalSeconds % 60;

            if (minutes <= 0) {
                return `${remainingSeconds}s`;
            }

            return `${minutes}m ${remainingSeconds}s`;
        }

        function percentage(value) {
            return Math.max(0, Math.min(100, Math.round(numberValue(value))));
        }

        function setProgress(id, value) {
            const element = document.getElementById(id);

            if (element) {
                element.style.width = `${percentage(value)}%`;
            }
        }

        function collectionSuccessRate(successful, failed) {
            const successfulCount = numberValue(successful);
            const failedCount = numberValue(failed);
            const total = successfulCount + failedCount;

            return total > 0 ? Math.round((successfulCount / total) * 100) : 0;
        }

        function navigationEfficiency(data) {
            const obstacleEvents = numberValue(data?.obstacle_count);
            const runtime = numberValue(data?.operating_duration);

            if (runtime <= 0 && obstacleEvents <= 0) {
                return 0;
            }

            return percentage(100 - (obstacleEvents * 7));
        }

        function navigationMode(status) {
            const normalizedStatus = String(status ?? '').toLowerCase();

            if (['moving', 'running', 'active', 'started', 'start', 'collecting', 'detecting', 'camera detection'].includes(normalizedStatus)) {
                return 'Path tracking';
            }

            if (['stopped', 'stop', 'emergency stop'].includes(normalizedStatus)) {
                return 'Safety stop';
            }

            if (['idle', 'reset', 'paused'].includes(normalizedStatus)) {
                return 'Standby';
            }

            return 'Awaiting telemetry';
        }

        function updateCapabilityAndEvaluation(data) {
            const detectedTrashCount = numberValue(data?.detected_trash_count);
            const successfulCount = numberValue(data?.successful_pickup_count);
            const failedCount = numberValue(data?.failed_pickup_count);
            const pickupAttempts = successfulCount + failedCount;
            const plasticCount = numberValue(data?.plastic_waste_count);
            const generalCount = numberValue(data?.general_waste_count);
            const totalWasteCount = plasticCount + generalCount;
            const detectionAccuracy = percentage(data?.detection_confidence);
            const navigationScore = navigationEfficiency(data);
            const collectionScore = collectionSuccessRate(successfulCount, failedCount);
            const batteryScore = percentage(data?.battery_level);
            const loadScore = percentage((totalWasteCount / loadCapacityLimit) * 100);
            const runtime = formatDuration(data?.operating_duration ?? 0);
            const obstacleEvents = numberValue(data?.obstacle_count);
            const lastDetectedType = data?.last_detected_trash_type ?? 'No classification';

            setText('module_detection_meta', `${detectedTrashCount} detected | ${lastDetectedType}`);
            setText('module_navigation_status', navigationMode(data?.movement_status));
            setText('module_navigation_meta', `${obstacleEvents} obstacles, ${runtime}`);
            setText('module_arm_status', pickupAttempts === 0 ? 'Awaiting pickups' : (collectionScore >= 70 ? 'Collection stable' : 'Needs tuning'));
            setText('module_arm_meta', `${successfulCount} success, ${failedCount} failed`);
            setText('module_wireless_status', data ? 'Telemetry active' : 'Awaiting robot data');
            setText('module_wireless_meta', data?.created_at ? 'Latest update received' : 'Waiting for first log');

            setText('evaluation_detection_accuracy', detectionAccuracy);
            setText('evaluation_navigation_efficiency', navigationScore);
            setText('evaluation_collection_success', collectionScore);
            setText('evaluation_battery_endurance', batteryScore);
            setText('evaluation_load_capacity', loadScore);

            setProgress('evaluation_detection_accuracy_bar', detectionAccuracy);
            setProgress('evaluation_navigation_efficiency_bar', navigationScore);
            setProgress('evaluation_collection_success_bar', collectionScore);
            setProgress('evaluation_battery_endurance_bar', batteryScore);
            setProgress('evaluation_load_capacity_bar', loadScore);

            setText('evaluation_detection_note', detectionAccuracy > 0 ? 'Latest model confidence' : 'Awaiting model confidence');
            setText('evaluation_navigation_note', `${obstacleEvents} obstacle events`);
            setText('evaluation_collection_note', `${pickupAttempts} pickup attempts`);
            setText('evaluation_battery_note', `Runtime ${runtime}`);
            setText('evaluation_load_note', `${totalWasteCount} / ${loadCapacityLimit} lightweight items`);
        }

        function setCommandMessage(message, tone = 'secondary') {
            if (!commandMessage) {
                return;
            }

            commandMessage.className = `toast-line mt-3 bg-${tone}-subtle text-${tone}-emphasis`;
            commandMessage.innerText = message;
        }

        function setCommandLoading(isLoading) {
            commandButtons.forEach((button) => {
                button.disabled = isLoading;
            });
        }

        function updateLatestRobotData(data) {
            if (!data) {
                return;
            }

            latestRobotData = data;

            updateStatus(data.movement_status ?? 'No Data');
            updateBattery(data.battery_level ?? 0);
            setText('detected_trash_count', data.detected_trash_count ?? 0);
            setText('successful_pickup_count', data.successful_pickup_count ?? 0);
            setText('failed_pickup_count', data.failed_pickup_count ?? 0);
            setText('obstacle_count', data.obstacle_count ?? 0);
            setText('operating_duration', formatDuration(data.operating_duration ?? 0));
            setText('plastic_waste_count', data.plastic_waste_count ?? 0);
            setText('general_waste_count', data.general_waste_count ?? 0);
            setText('plastic_bin_level', data.plastic_bin_level ?? 'Empty');
            setText('general_bin_level', data.general_bin_level ?? 'Empty');
            setText('last_detected_trash_type', data.last_detected_trash_type ?? 'No Data');
            setText('connection_status', 'Live polling');

            updateSuccessRate(data.successful_pickup_count ?? 0, data.failed_pickup_count ?? 0);
            updateWasteTotals(data.plastic_waste_count ?? 0, data.general_waste_count ?? 0);
            updateCapabilityAndEvaluation(data);

            if (data.created_at) {
                const updatedAt = new Date(data.created_at);

                if (!Number.isNaN(updatedAt.getTime())) {
                    setText('last_updated', updatedAt.toLocaleString([], {
                        month: 'short',
                        day: '2-digit',
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                    }));
                }
            }
        }

        async function fetchLatestRobotData() {
            try {
                const response = await fetch(latestRobotUrl, {
                    headers: {
                        Accept: 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Latest robot request failed.');
                }

                updateLatestRobotData(await response.json());
            } catch (error) {
                setText('connection_status', 'Polling interrupted');
                console.error(error);
            }
        }

        async function sendRobotCommand(command) {
            setCommandLoading(true);
            setCommandMessage(`Sending ${command} command...`, 'secondary');

            try {
                const response = await fetch(commandRobotUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        robot_id: 'ROBOT-001',
                        command,
                    }),
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message ?? 'Command request failed.');
                }

                setCommandMessage(`${command} command sent successfully.`, 'success');
            } catch (error) {
                setCommandMessage(error.message || 'Error sending command.', 'danger');
                console.error(error);
            } finally {
                setCommandLoading(false);
            }
        }

        function setCameraStatus(label, tone = 'secondary') {
            if (!cameraStatus) {
                return;
            }

            cameraStatus.className = `status-pill status-${tone}`;
            cameraStatus.innerHTML = '<span class="status-dot"></span>' + label;
        }

        function setCameraMessage(message, tone = 'secondary') {
            if (!cameraMessage) {
                return;
            }

            cameraMessage.className = `toast-line mt-3 bg-${tone}-subtle text-${tone}-emphasis`;
            cameraMessage.innerText = message;
        }

        function setConfidence(confidence) {
            if (!cameraConfidenceLabel || !cameraConfidenceBar) {
                return;
            }

            const normalizedConfidence = Math.max(0, Math.min(100, Math.round(numberValue(confidence))));

            cameraConfidenceLabel.innerText = `${normalizedConfidence}%`;
            cameraConfidenceBar.style.width = `${normalizedConfidence}%`;
        }

        function setCameraButtons(isCameraActive) {
            if (!startCameraButton || !liveDetectionButton || !stopCameraButton) {
                return;
            }

            startCameraButton.disabled = isCameraActive;
            liveDetectionButton.disabled = !isCameraActive;
            stopCameraButton.disabled = !isCameraActive;
        }

        function setLiveDetectionButton()
        {
            if (!liveDetectionButton) {
                return;
            }

            if (!cameraStream && !isLiveDetecting) {
                liveDetectionButton.innerText = 'Live Detection';
                return;
            }

            liveDetectionButton.innerText = isLiveDetecting ? 'Pause Live Detection' : 'Resume Live Detection';
        }

        function setCameraModelLabel(label, tone = 'secondary') {
            if (!cameraModelLabel) {
                return;
            }

            cameraModelLabel.className = `badge bg-${tone}-subtle text-${tone}-emphasis border border-${tone}-subtle px-3 py-2`;
            cameraModelLabel.innerText = label;
        }

        async function loadObjectDetectionModel() {
            if (objectDetectionModel) {
                return objectDetectionModel;
            }

            if (objectDetectionModelPromise) {
                return objectDetectionModelPromise;
            }

            if (!window.tf || !window.cocoSsd) {
                throw new Error('The object detection library did not load.');
            }

            setCameraModelLabel('Loading model', 'warning');

            objectDetectionModelPromise = (async () => {
                await tf.ready();
                objectDetectionModel = await cocoSsd.load({
                    base: 'mobilenet_v2',
                });
                setCameraModelLabel(cameraModelName, 'success');

                return objectDetectionModel;
            })().catch((error) => {
                objectDetectionModelPromise = null;
                setCameraModelLabel('Model unavailable', 'danger');

                throw error;
            });

            return objectDetectionModelPromise;
        }

        function mapObjectToWasteCategory(objectLabel) {
            return wasteCategoryByObject[objectLabel] ?? 'General Waste';
        }

        function chooseBestPrediction(predictions) {
            if (!predictions.length) {
                return null;
            }

            const wasteRelatedPrediction = predictions.find((prediction) => wasteCategoryByObject[prediction.class]);

            return wasteRelatedPrediction ?? predictions[0];
        }

        function normalizeBoundingBox(prediction) {
            if (!prediction?.bbox) {
                return null;
            }

            const [x, y, width, height] = prediction.bbox;

            return {
                x: Math.round(x),
                y: Math.round(y),
                width: Math.round(width),
                height: Math.round(height),
            };
        }

        function drawDetectionOverlay(predictions, sourceWidth, sourceHeight) {
            if (!detectionOverlay) {
                return;
            }

            const bounds = detectionOverlay.getBoundingClientRect();
            const context = detectionOverlay.getContext('2d');

            if (!context) {
                return;
            }

            const displayWidth = Math.max(1, Math.round(bounds.width));
            const displayHeight = Math.max(1, Math.round(bounds.height));
            const scaleX = displayWidth / sourceWidth;
            const scaleY = displayHeight / sourceHeight;

            detectionOverlay.width = displayWidth;
            detectionOverlay.height = displayHeight;
            context.clearRect(0, 0, displayWidth, displayHeight);
            detectionOverlay.classList.remove('d-none');

            predictions.slice(0, 5).forEach((prediction) => {
                const [x, y, width, height] = prediction.bbox;
                const label = `${prediction.class} ${Math.round(prediction.score * 100)}%`;
                const boxX = x * scaleX;
                const boxY = y * scaleY;
                const boxWidth = width * scaleX;
                const boxHeight = height * scaleY;

                context.strokeStyle = '#38bdf8';
                context.lineWidth = 3;
                context.strokeRect(boxX, boxY, boxWidth, boxHeight);
                context.font = '700 13px Segoe UI, sans-serif';
                context.textBaseline = 'top';

                const labelWidth = context.measureText(label).width + 14;
                context.fillStyle = 'rgba(15, 23, 42, 0.86)';
                context.fillRect(boxX, Math.max(0, boxY - 24), labelWidth, 24);
                context.fillStyle = '#ffffff';
                context.fillText(label, boxX + 7, Math.max(0, boxY - 20));
            });
        }

        async function detectTrashFromCanvas(canvas) {
            const model = await loadObjectDetectionModel();
            const predictions = await model.detect(canvas, 10, 0.35);
            const bestPrediction = chooseBestPrediction(predictions);

            drawDetectionOverlay(predictions, canvas.width, canvas.height);

            if (!bestPrediction) {
                return {
                    type: 'General Waste',
                    confidence: 0,
                    objectLabel: 'No object detected',
                    bbox: null,
                    predictions,
                };
            }

            return {
                type: mapObjectToWasteCategory(bestPrediction.class),
                confidence: bestPrediction.score * 100,
                objectLabel: bestPrediction.class,
                bbox: normalizeBoundingBox(bestPrediction),
                predictions,
            };
        }

        function snapshotCameraFrame() {
            if (!cameraFeed || !cameraSnapshot) {
                return false;
            }

            if (!cameraFeed.videoWidth || !cameraFeed.videoHeight) {
                return false;
            }

            cameraSnapshot.width = cameraFeed.videoWidth;
            cameraSnapshot.height = cameraFeed.videoHeight;
            cameraSnapshot.getContext('2d').drawImage(cameraFeed, 0, 0, cameraSnapshot.width, cameraSnapshot.height);

            return true;
        }

        function applyCameraDetectionResult(detection) {
            if (!cameraTrashType || !cameraObjectLabel || !saveCameraDetectionButton || !cameraEvidencePreview || !cameraSnapshot) {
                return;
            }

            const hasDetectedObject = detection.objectLabel !== 'No object detected' && numberValue(detection.confidence) > 0;

            cameraTrashType.value = detection.type;
            cameraObjectLabel.innerText = detection.objectLabel;
            setConfidence(detection.confidence);
            saveCameraDetectionButton.disabled = isSavingCameraDetection || !hasDetectedObject;

            if (hasDetectedObject) {
                cameraEvidencePreview.src = cameraSnapshot.toDataURL('image/jpeg', 0.82);
                cameraEvidencePreview.classList.add('is-visible');
                setCameraMessage(`${detection.objectLabel} detected live and mapped to ${detection.type}.`, 'success');
                return;
            }

            setCameraMessage('Live detection is scanning the camera feed.', 'secondary');
        }

        function scheduleLiveDetection(runId) {
            clearTimeout(liveDetectionTimer);

            liveDetectionTimer = window.setTimeout(() => {
                runLiveDetectionFrame(runId);
            }, liveDetectionInterval);
        }

        async function runLiveDetectionFrame(runId) {
            if (!isLiveDetecting || runId !== liveDetectionRunId || !cameraStream) {
                return;
            }

            if (!snapshotCameraFrame()) {
                scheduleLiveDetection(runId);
                return;
            }

            try {
                const detection = await detectTrashFromCanvas(cameraSnapshot);

                if (!isLiveDetecting || runId !== liveDetectionRunId) {
                    return;
                }

                lastCameraDetection = detection;
                setCameraStatus('Live detecting', 'success');
                applyCameraDetectionResult(detection);
            } catch (error) {
                if (!isLiveDetecting || runId !== liveDetectionRunId) {
                    return;
                }

                pauseLiveDetection('Detection paused after a model error.', 'danger');
                setCameraStatus('Detection failed', 'danger');
                console.error(error);

                return;
            }

            scheduleLiveDetection(runId);
        }

        function startLiveDetection() {
            if (!cameraStream) {
                setCameraMessage('Start the laptop camera before live detection.', 'warning');
                return;
            }

            if (isLiveDetecting) {
                return;
            }

            isLiveDetecting = true;
            liveDetectionRunId += 1;
            setLiveDetectionButton();
            setCameraStatus('Live detecting', 'success');
            setCameraMessage('Live detection is scanning the camera feed.', 'secondary');
            runLiveDetectionFrame(liveDetectionRunId);
        }

        function pauseLiveDetection(message = 'Live detection paused. Resume to continue scanning.', tone = 'warning') {
            isLiveDetecting = false;
            liveDetectionRunId += 1;
            clearTimeout(liveDetectionTimer);
            setLiveDetectionButton();

            if (cameraStream) {
                setCameraStatus('Camera live', 'success');
            }

            setCameraMessage(message, tone);
        }

        function stopLiveDetection() {
            isLiveDetecting = false;
            liveDetectionRunId += 1;
            clearTimeout(liveDetectionTimer);
            liveDetectionTimer = null;
            setLiveDetectionButton();
        }

        function toggleLiveDetection() {
            if (isLiveDetecting) {
                pauseLiveDetection();
                return;
            }

            startLiveDetection();
        }

        async function startLaptopCamera() {
            if (!cameraFeed || !cameraSnapshot || !detectionOverlay || !cameraEvidencePreview || !cameraPlaceholder || !liveDetectionButton) {
                return;
            }

            if (!navigator.mediaDevices?.getUserMedia) {
                setCameraMessage('This browser does not support camera access.', 'danger');
                return;
            }

            try {
                try {
                    cameraStream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: { ideal: 'environment' },
                            width: { ideal: 1280 },
                            height: { ideal: 720 },
                        },
                        audio: false,
                    });
                } catch (error) {
                    cameraStream = await navigator.mediaDevices.getUserMedia({
                        video: true,
                        audio: false,
                    });
                }

                cameraFeed.srcObject = cameraStream;
                cameraFeed.classList.remove('d-none');
                cameraSnapshot.classList.add('d-none');
                detectionOverlay.classList.add('d-none');
                cameraEvidencePreview.classList.remove('is-visible');
                cameraEvidencePreview.removeAttribute('src');
                cameraPlaceholder.classList.add('is-hidden');
                setCameraButtons(true);
                liveDetectionButton.disabled = true;
                setLiveDetectionButton();
                setCameraStatus('Camera live', 'success');
                setCameraMessage('Camera is active. Loading the object detection model...', 'secondary');
                await loadObjectDetectionModel();
                liveDetectionButton.disabled = false;
                startLiveDetection();
            } catch (error) {
                setCameraStatus('Camera/model error', 'danger');
                setCameraMessage(error.message || 'Camera permission was blocked or no camera is available.', 'danger');
                console.error(error);
            }
        }

        function stopLaptopCamera() {
            if (!cameraFeed || !cameraSnapshot || !detectionOverlay || !cameraPlaceholder || !cameraEvidencePreview || !cameraObjectLabel || !saveCameraDetectionButton) {
                return;
            }

            stopLiveDetection();

            if (cameraStream) {
                cameraStream.getTracks().forEach((track) => track.stop());
            }

            cameraStream = null;
            cameraFeed.srcObject = null;
            cameraFeed.classList.add('d-none');
            cameraSnapshot.classList.add('d-none');
            detectionOverlay.classList.add('d-none');
            cameraPlaceholder.classList.remove('is-hidden');
            cameraEvidencePreview.classList.remove('is-visible');
            cameraEvidencePreview.removeAttribute('src');
            cameraObjectLabel.innerText = 'Waiting for live detection';
            setConfidence(0);
            setCameraButtons(false);
            setLiveDetectionButton();
            setCameraStatus('Camera off', 'secondary');
            saveCameraDetectionButton.disabled = true;
            lastCameraDetection = null;
        }

        function prependCameraHistory(type, confidence) {
            if (!cameraDetectionHistory) {
                return;
            }

            if (cameraDetectionHistory.querySelector('.text-muted')) {
                cameraDetectionHistory.innerHTML = '';
            }

            const item = document.createElement('div');
            const title = document.createElement('div');
            const typeLabel = document.createElement('span');
            const confidenceLabel = document.createElement('span');
            const meta = document.createElement('div');

            item.className = 'detection-item';
            title.className = 'd-flex justify-content-between gap-3 fw-semibold text-dark';
            meta.className = 'small text-muted mt-1';
            typeLabel.innerText = lastCameraDetection?.objectLabel
                ? `${type} (${lastCameraDetection.objectLabel})`
                : type;
            confidenceLabel.innerText = `${Math.round(confidence)}%`;
            meta.innerText = new Date().toLocaleTimeString([], {
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
            });

            title.append(typeLabel, confidenceLabel);
            item.append(title, meta);
            cameraDetectionHistory.prepend(item);
        }

        async function saveCameraDetection() {
            if (!cameraSnapshot || !saveCameraDetectionButton) {
                return;
            }

            if (!lastCameraDetection || !cameraSnapshot.width || !cameraSnapshot.height) {
                setCameraMessage('Wait for live detection to identify an object before saving.', 'warning');
                return;
            }

            const selectedType = cameraTrashType.value;
            const confidence = lastCameraDetection?.confidence ?? numberValue(cameraConfidenceLabel.innerText);
            const isPlastic = selectedType.toLowerCase().includes('plastic');
            const detectedTrashCount = numberValue(latestRobotData?.detected_trash_count) + 1;
            const plasticWasteCount = numberValue(latestRobotData?.plastic_waste_count) + (isPlastic ? 1 : 0);
            const generalWasteCount = numberValue(latestRobotData?.general_waste_count) + (isPlastic ? 0 : 1);

            isSavingCameraDetection = true;
            saveCameraDetectionButton.disabled = true;
            setCameraMessage(`Saving ${selectedType} detection...`, 'secondary');

            try {
                const response = await fetch(robotLogUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        robot_id: latestRobotData?.robot_id ?? 'ROBOT-001',
                        battery_level: latestRobotData?.battery_level ?? 0,
                        movement_status: 'Camera Detection',
                        detected_trash_count: detectedTrashCount,
                        successful_pickup_count: latestRobotData?.successful_pickup_count ?? 0,
                        failed_pickup_count: latestRobotData?.failed_pickup_count ?? 0,
                        obstacle_count: latestRobotData?.obstacle_count ?? 0,
                        operating_duration: latestRobotData?.operating_duration ?? 0,
                        plastic_waste_count: plasticWasteCount,
                        general_waste_count: generalWasteCount,
                        last_detected_trash_type: selectedType,
                        detection_source: 'laptop_camera',
                        classification_model: cameraModelName,
                        detected_object_label: lastCameraDetection?.objectLabel ?? 'Manual review',
                        detection_confidence: confidence,
                        detection_bbox: lastCameraDetection?.bbox,
                        camera_snapshot: cameraSnapshot.toDataURL('image/jpeg', 0.82),
                        plastic_bin_level: latestRobotData?.plastic_bin_level ?? 'Empty',
                        general_bin_level: latestRobotData?.general_bin_level ?? 'Empty',
                        remarks: `Laptop camera detected ${lastCameraDetection?.objectLabel ?? 'an reviewed item'} and classified it as ${selectedType}.`,
                    }),
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message ?? 'Camera detection could not be saved.');
                }

                updateLatestRobotData(data.data);
                prependCameraHistory(selectedType, confidence);
                setCameraStatus('Detection saved', 'success');
                setCameraMessage(`${selectedType} detection saved to robot logs.`, 'success');
            } catch (error) {
                setCameraMessage(error.message || 'Error saving camera detection.', 'danger');
                console.error(error);
            } finally {
                isSavingCameraDetection = false;
                saveCameraDetectionButton.disabled = !lastCameraDetection || lastCameraDetection.objectLabel === 'No object detected';
            }
        }

        commandButtons.forEach((button) => {
            button.addEventListener('click', () => sendRobotCommand(button.dataset.command));
        });

        startCameraButton?.addEventListener('click', startLaptopCamera);
        liveDetectionButton?.addEventListener('click', toggleLiveDetection);
        stopCameraButton?.addEventListener('click', stopLaptopCamera);
        saveCameraDetectionButton?.addEventListener('click', saveCameraDetection);
        cameraTrashType?.addEventListener('change', () => {
            lastCameraDetection = {
                type: cameraTrashType.value,
                confidence: numberValue(cameraConfidenceLabel?.innerText),
                objectLabel: lastCameraDetection?.objectLabel ?? 'Manual review',
                bbox: lastCameraDetection?.bbox ?? null,
            };
        });

        if (cameraFeed) {
            window.addEventListener('beforeunload', stopLaptopCamera);
        }

        fetchLatestRobotData();
        setInterval(fetchLatestRobotData, 3000);
    </script>
</body>
</html>
