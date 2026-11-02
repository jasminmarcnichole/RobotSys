<section id="overview" class="flex flex-col gap-7">
    <div class="workspace-welcome"><div><p class="text-xs font-semibold tracking-wider text-emerald-200 uppercase">Your operations, in focus</p><h2 class="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">A clear view. A confident next step.</h2><p class="mt-3 max-w-xl text-sm leading-7 text-emerald-100">Review reported performance, keep an eye on the camera, and follow every action from one workspace.</p></div><a href="{{ route('robot.dashboard.control', ['robot_id' => $robotId]) }}" class="button border-white/25 bg-white/10 text-white hover:bg-white/20">Open robot app<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4 invert" alt="" aria-hidden="true"></a></div>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold">Robot monitoring</h2>
        <a href="{{ route('robot.dashboard.camera', ['robot_id' => $robotId]) }}" class="button button-primary">
            <img src="{{ asset('icons/camera.svg') }}" class="size-4 invert" alt="" aria-hidden="true">
            Open camera
        </a>
    </div>
    <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" data-primary-metrics>
        <article class="metric-card">
            <div class="metric-heading"><h3>Battery level</h3><img src="{{ asset('icons/battery.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="metric-value"><span id="battery_level">{{ $latestLog ? number_format($batteryLevel, 0) : '--' }}</span><span class="metric-unit">%</span></p>
            <div class="progress-track mt-4"><span id="battery_bar" class="progress-fill bg-emerald-600" style="width: {{ min(100, max(0, $batteryLevel)) }}%"></span></div>
            <p class="mt-3 text-xs text-zinc-500">Latest reported charge</p>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><h3>Detected trash</h3><img src="{{ asset('icons/scan-line.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p id="detected_trash_count" class="metric-value">{{ (int) ($latestLog?->detected_trash_count ?? 0) }}</p>
            <p class="mt-4 text-xs text-zinc-500">Latest category</p>
            <p id="last_detected_trash_type" class="mt-1 break-words text-sm font-medium text-zinc-700">{{ $latestLog?->last_detected_trash_type ?? 'Not reported' }}</p>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><h3>Pickup success</h3><img src="{{ asset('icons/check.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="metric-value"><span id="success_rate">{{ $totalPickups > 0 ? $successRate : '--' }}</span><span class="metric-unit">%</span></p>
            <p class="mt-4 text-xs text-zinc-500">Successful / total attempts</p>
            <p id="evaluation_collection_note" class="mt-1 text-sm font-medium text-zinc-700">{{ $totalPickups > 0 ? $totalPickups.' pickup attempts' : 'Awaiting pickups' }}</p>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><h3>Operating time</h3><img src="{{ asset('icons/activity.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p id="operating_duration" class="metric-value">{{ $latestLog ? $formattedOperatingDuration : '--' }}</p>
            <p class="mt-4 text-xs text-zinc-500">Reported runtime</p>
            <p class="mt-1 text-sm font-medium text-zinc-700">Robot telemetry</p>
        </article>
    </div>
    <div class="grid gap-8 xl:grid-cols-[1.25fr_1fr]">
        <section aria-labelledby="collection-heading">
            <div class="section-heading"><h2 id="collection-heading">Collection activity</h2><a href="{{ route('robot.dashboard.evaluation', ['robot_id' => $robotId]) }}" class="text-link">Evaluation<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></div>
            <dl class="divide-y divide-zinc-200">
                <div class="data-row"><dt class="flex items-center gap-3"><span class="size-2 rounded-full bg-emerald-600" aria-hidden="true"></span>Successful pickups</dt><dd id="successful_pickup_count" class="text-lg font-semibold tabular-nums">{{ $successfulPickups }}</dd></div>
                <div class="data-row"><dt class="flex items-center gap-3"><span class="size-2 rounded-full bg-red-500" aria-hidden="true"></span>Failed pickups</dt><dd id="failed_pickup_count" class="text-lg font-semibold tabular-nums">{{ $failedPickups }}</dd></div>
                <div class="data-row"><dt class="flex items-center gap-3"><span class="size-2 rounded-full bg-amber-500" aria-hidden="true"></span>Obstacle events</dt><dd id="obstacle_count" class="text-lg font-semibold tabular-nums">{{ $obstacleCount }}</dd></div>
                <div class="data-row"><dt class="flex items-center gap-3"><img src="{{ asset('icons/wifi.svg') }}" class="size-4" alt="" aria-hidden="true">Telemetry</dt><dd id="wireless_status" class="text-right text-sm font-medium">{{ $wirelessStatus }}</dd></div>
            </dl>
        </section>
        <section aria-labelledby="operations-heading">
            <div class="section-heading"><h2 id="operations-heading">Operations</h2><span class="text-xs text-zinc-500">{{ $robotId }}</span></div>
            <div class="divide-y divide-zinc-200">
                <a href="{{ route('robot.dashboard.commands', ['robot_id' => $robotId]) }}" class="operation-link"><img src="{{ asset('icons/command.svg') }}" class="size-5" alt="" aria-hidden="true"><span class="flex-1">Robot commands</span><img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a>
                <a href="{{ route('robot.dashboard.waste', ['robot_id' => $robotId]) }}" class="operation-link"><img src="{{ asset('icons/trash-2.svg') }}" class="size-5" alt="" aria-hidden="true"><span class="flex-1">Waste sorting</span><img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a>
                <a href="{{ route('robot.dashboard.logs', ['robot_id' => $robotId]) }}" class="operation-link"><img src="{{ asset('icons/clipboard-list.svg') }}" class="size-5" alt="" aria-hidden="true"><span class="flex-1">Activity logs</span><img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a>
            </div>
        </section>
    </div>
    @include('robot.partials.workspace-insights')
</section>
