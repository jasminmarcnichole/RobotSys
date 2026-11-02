<section id="capabilities" class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold">System Capability Map</h2>
        <span class="text-xs text-zinc-500">{{ $robotId }}</span>
    </div>

    <section aria-labelledby="system-modules-heading">
        <div class="section-heading"><h3 id="system-modules-heading">System modules</h3><img src="{{ asset('icons/cpu.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
        <div class="table-scroll" role="region" aria-label="System modules" tabindex="0">
            <table class="operations-table min-w-[620px]">
                <thead><tr><th scope="col">Module</th><th scope="col">Current record</th><th scope="col">Workspace</th></tr></thead>
                <tbody>
                    <tr>
                        <td><span class="flex items-center gap-3 font-medium"><img src="{{ asset('icons/activity.svg') }}" class="size-4" alt="" aria-hidden="true">Movement controller</span></td>
                        <td>{{ $latestStatus }}</td>
                        <td><a href="{{ route('robot.dashboard.commands', ['robot_id' => $robotId]) }}" class="text-link">Commands<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></td>
                    </tr>
                    <tr>
                        <td><span class="flex items-center gap-3 font-medium"><img src="{{ asset('icons/scan-line.svg') }}" class="size-4" alt="" aria-hidden="true">Object detector</span></td>
                        <td><span class="block">EfficientDet Lite2 / Lite0</span><span class="mt-1 block text-xs text-zinc-500">TensorFlow Lite / browser runtime</span></td>
                        <td><a href="{{ route('robot.dashboard.camera', ['robot_id' => $robotId]) }}" class="text-link">Camera<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></td>
                    </tr>
                    <tr>
                        <td><span class="flex items-center gap-3 font-medium"><img src="{{ asset('icons/camera.svg') }}" class="size-4" alt="" aria-hidden="true">ESP32-CAM input</span></td>
                        <td><span class="status-badge {{ config('services.robot.camera_snapshot_url') ? 'bg-blue-50 text-blue-700 ring-blue-200' : 'bg-zinc-100 text-zinc-600 ring-zinc-200' }}">{{ config('services.robot.camera_snapshot_url') ? 'Configured' : 'Not configured' }}</span></td>
                        <td><a href="{{ route('robot.dashboard.camera', ['robot_id' => $robotId]) }}" class="text-link">Camera<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></td>
                    </tr>
                    <tr>
                        <td><span class="flex items-center gap-3 font-medium"><img src="{{ asset('icons/trash-2.svg') }}" class="size-4" alt="" aria-hidden="true">Collection bins</span></td>
                        <td><span class="block text-xs">Plastic: <span id="plastic_bin_level">{{ $latestLog?->plastic_bin_level ?? 'Not reported' }}</span></span><span class="mt-1 block text-xs text-zinc-500">General: <span id="general_bin_level">{{ $latestLog?->general_bin_level ?? 'Not reported' }}</span></span></td>
                        <td><a href="{{ route('robot.dashboard.waste', ['robot_id' => $robotId]) }}" class="text-link">Waste sorting<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></td>
                    </tr>
                    <tr>
                        <td><span class="flex items-center gap-3 font-medium"><img src="{{ asset('icons/wifi.svg') }}" class="size-4" alt="" aria-hidden="true">Telemetry link</span></td>
                        <td id="wireless_status">{{ $wirelessStatus }}</td>
                        <td><a href="{{ route('robot.dashboard.logs', ['robot_id' => $robotId]) }}" class="text-link">Activity logs<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></td>
                    </tr>
                    <tr>
                        <td><span class="flex items-center gap-3 font-medium"><img src="{{ asset('icons/eye.svg') }}" class="size-4" alt="" aria-hidden="true">Waste category review</span></td>
                        <td>Operator confirmation required</td>
                        <td><a href="{{ route('robot.dashboard.logs', ['source' => 'laptop_camera']) }}" class="text-link">Saved detections<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-8 md:grid-cols-2">
        <section aria-labelledby="reported-operation-heading">
            <div class="section-heading"><h3 id="reported-operation-heading">Reported operation</h3></div>
            <dl class="divide-y divide-zinc-200">
                <div class="data-row"><dt class="text-zinc-500">Runtime</dt><dd id="operating_duration" class="font-medium tabular-nums">{{ $latestLog ? $formattedOperatingDuration : '--' }}</dd></div>
                <div class="data-row"><dt class="text-zinc-500">Obstacle events</dt><dd id="obstacle_count" class="font-medium tabular-nums">{{ $obstacleCount }}</dd></div>
            </dl>
        </section>
        <section aria-labelledby="detection-record-heading">
            <div class="section-heading"><h3 id="detection-record-heading">Latest saved detection</h3></div>
            <dl class="divide-y divide-zinc-200">
                <div class="data-row"><dt class="text-zinc-500">Object</dt><dd class="text-right font-medium">{{ ($latestDetection ?? null)?->detected_object_label ?? 'No saved detection' }}</dd></div>
                <div class="data-row"><dt class="text-zinc-500">Confidence</dt><dd class="font-medium tabular-nums">{{ ($latestDetection ?? null)?->detection_confidence !== null ? number_format($latestDetectionConfidence, 0).'%' : '--' }}</dd></div>
            </dl>
        </section>
    </div>
</section>
