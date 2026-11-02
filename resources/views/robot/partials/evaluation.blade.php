<section id="evaluation" class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold">Performance Evaluation</h2>
        <a href="{{ route('robot.dashboard.logs', ['robot_id' => $robotId]) }}" class="text-link">View records<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <article class="metric-card">
            <div class="metric-heading"><h3>Object confidence</h3><img src="{{ asset('icons/scan-line.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="metric-value"><span id="evaluation_detection_accuracy">{{ ($latestDetection ?? null)?->detection_confidence !== null ? $detectionConfidence : '--' }}</span><span class="metric-unit">%</span></p>
            <div class="progress-track mt-4"><span id="evaluation_detection_accuracy_bar" class="progress-fill bg-blue-600" style="width: {{ $detectionConfidence }}%"></span></div>
            <p id="evaluation_detection_note" class="mt-3 text-xs text-zinc-500">{{ ($latestDetection ?? null)?->detected_object_label ?? 'Awaiting a saved detection' }}</p>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><h3>Obstacle events</h3><img src="{{ asset('icons/activity.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="metric-value"><span id="evaluation_obstacle_count">{{ $obstacleCount }}</span><span class="metric-unit">events</span></p>
            <span id="evaluation_navigation_efficiency" class="hidden" aria-hidden="true"></span>
            <span id="evaluation_navigation_efficiency_bar" class="hidden" aria-hidden="true"></span>
            <p id="evaluation_navigation_note" class="mt-4 text-xs leading-5 text-zinc-500">Reported obstacle count</p>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><h3>Pickup success</h3><img src="{{ asset('icons/check.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="metric-value"><span id="evaluation_collection_success">{{ $totalPickups > 0 ? $successRate : '--' }}</span><span class="metric-unit">%</span></p>
            <div class="progress-track mt-4"><span id="evaluation_collection_success_bar" class="progress-fill bg-emerald-600" style="width: {{ $successRate }}%"></span></div>
            <p id="evaluation_collection_note" class="mt-3 text-xs text-zinc-500">{{ $totalPickups }} pickup attempts</p>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><h3>Battery level</h3><img src="{{ asset('icons/battery.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="metric-value"><span id="evaluation_battery_endurance">{{ $latestLog ? (int) min(100, max(0, $batteryLevel)) : '--' }}</span><span class="metric-unit">%</span></p>
            <div class="progress-track mt-4"><span id="evaluation_battery_endurance_bar" class="progress-fill bg-emerald-600" style="width: {{ min(100, max(0, $batteryLevel)) }}%"></span></div>
            <p id="evaluation_battery_note" class="mt-3 text-xs text-zinc-500">{{ $latestLog ? 'Runtime '.$formattedOperatingDuration : 'Awaiting robot telemetry' }}</p>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><h3>Reference load</h3><img src="{{ asset('icons/package.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="metric-value"><span id="evaluation_load_capacity">{{ $latestLog ? $loadCapacityUsage : '--' }}</span><span class="metric-unit">%</span></p>
            <div class="progress-track mt-4"><span id="evaluation_load_capacity_bar" class="progress-fill bg-amber-500" style="width: {{ $loadCapacityUsage }}%"></span></div>
            <p id="evaluation_load_note" class="mt-3 text-xs text-zinc-500">{{ $totalWasteCount }} / {{ $loadCapacityLimit }}-item reference</p>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><h3>Measured accuracy</h3><img src="{{ asset('icons/chart-no-axes-combined.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="mt-5 text-xl font-semibold">Not evaluated</p>
            <p class="mt-4 text-xs leading-5 text-zinc-500">No labelled validation results available.</p>
        </article>
    </div>
    <div class="flex items-start gap-3 border-y border-zinc-200 py-4 text-sm leading-6 text-zinc-600">
        <img src="{{ asset('icons/circle-help.svg') }}" class="mt-1 size-4 shrink-0" alt="" aria-hidden="true">
        <p>Confidence is the model's score for an object, not measured accuracy. Load is an item-count estimate, not measured bin volume.</p>
    </div>
    @include('robot.partials.workspace-insights')
</section>
