<section id="waste" class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold">Waste Sorting</h2>
        <a href="{{ route('robot.dashboard.logs', ['robot_id' => $robotId]) }}" class="text-link">Collection records<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a>
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        <article class="metric-card">
            <div class="metric-heading"><h3>Plastic waste</h3><img src="{{ asset('icons/trash-2.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="metric-value"><span id="plastic_waste_count">{{ $plasticWasteCount }}</span><span class="metric-unit">items</span></p>
            <div class="mt-5 flex items-center justify-between gap-3 border-t border-zinc-100 pt-4">
                <span class="text-xs text-zinc-500">Reported bin level</span>
                <span id="plastic_bin_level" class="status-badge bg-zinc-100 text-zinc-700 ring-zinc-200">{{ $latestLog?->plastic_bin_level ?? 'Not reported' }}</span>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><h3>General waste</h3><img src="{{ asset('icons/package.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
            <p class="metric-value"><span id="general_waste_count">{{ $generalWasteCount }}</span><span class="metric-unit">items</span></p>
            <div class="mt-5 flex items-center justify-between gap-3 border-t border-zinc-100 pt-4">
                <span class="text-xs text-zinc-500">Reported bin level</span>
                <span id="general_bin_level" class="status-badge bg-zinc-100 text-zinc-700 ring-zinc-200">{{ $latestLog?->general_bin_level ?? 'Not reported' }}</span>
            </div>
        </article>
    </div>
    <section aria-labelledby="load-heading" class="border-b border-zinc-200 pb-6">
        <div class="section-heading"><h3 id="load-heading">Collection load</h3><img src="{{ asset('icons/package.svg') }}" class="size-4" alt="" aria-hidden="true"></div>
        <div class="flex flex-wrap items-end justify-between gap-4 py-5">
            <div>
                <p class="text-sm text-zinc-500">Total collected</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums"><span id="total_waste_count">{{ $totalWasteCount }}</span><span class="ml-2 text-sm font-normal text-zinc-500">items</span></p>
            </div>
            <div class="text-right">
                <p class="text-xs text-zinc-500">{{ $loadCapacityLimit }}-item reference capacity</p>
                <p id="load_capacity_label" class="mt-2 text-xl font-semibold tabular-nums">{{ $latestLog ? $loadCapacityUsage.'%' : '--' }}</p>
            </div>
        </div>
        <div class="progress-track"><span id="load_capacity_bar" class="progress-fill bg-emerald-600" style="width: {{ $loadCapacityUsage }}%"></span></div>
        <p class="mt-3 text-xs leading-5 text-zinc-500">Count-based estimate. Actual bin volume and weight are not measured here.</p>
    </section>
    <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
        <span class="text-zinc-500">Collection totals come from robot telemetry.</span>
        <a href="{{ route('robot.dashboard.camera', ['robot_id' => $robotId]) }}" class="text-link">Review camera detections<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a>
    </div>
</section>
