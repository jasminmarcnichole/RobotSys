@php
    $trend = $workspaceInsights['battery_trend'] ?? [];
    $trendPoints = collect($trend)->map(fn ($point, $index) => (24 + $index * 592 / max(1, count($trend) - 1)).','.(144 - $point['battery'] * 1.2))->implode(' ');
@endphp
<section class="flex min-w-0 flex-col gap-5" data-workspace-insights aria-labelledby="insights-heading">
    <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="portal-eyebrow">A closer look</p><h2 id="insights-heading" class="mt-2 text-xl font-semibold tracking-tight">Workspace activity</h2></div><span class="text-xs text-zinc-500" data-insights-updated>Today · {{ $workspaceInsights['timezone'] }}</span></div>
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach (['telemetry_reports' => ['Telemetry reports today', 'Robot sensor updates'], 'camera_observations' => ['Camera records today', 'Saved image observations'], 'open_commands' => ['Open commands', 'Awaiting acknowledgement or completion']] as $key => [$heading, $note])
            <article class="metric-card"><h3 class="text-sm font-medium text-zinc-500">{{ $heading }}</h3><p class="mt-3 text-3xl font-semibold tabular-nums" data-insight-count="{{ $key }}">{{ number_format($workspaceInsights['today'][$key]) }}</p><p class="mt-2 text-xs leading-5 text-zinc-500">{{ $note }}</p></article>
        @endforeach
    </div>
    <div class="grid gap-5 xl:grid-cols-[1.2fr_1fr]">
        <section class="workspace-panel" aria-labelledby="battery-trend-heading">
            <div class="flex items-start justify-between gap-3"><div><h3 id="battery-trend-heading" class="font-semibold">Battery trend</h3><p class="mt-1 text-xs text-zinc-500">Up to 24 recent robot reports</p></div><span class="text-xl font-semibold tabular-nums text-emerald-800" data-insight-battery>{{ count($trend) ? round($trend[array_key_last($trend)]['battery']).'%' : '—' }}</span></div>
            <svg viewBox="0 0 640 168" class="mt-5 w-full text-emerald-700" role="img" aria-labelledby="battery-chart-title battery-chart-desc" data-battery-chart @if (count($trend) < 2) hidden @endif>
                <title id="battery-chart-title">Reported battery levels for {{ $robotId }}</title><desc id="battery-chart-desc">Battery percentages across recent reports, from oldest to newest. Report intervals may vary.</desc>
                @foreach ([24 => '100%', 84 => '50%', 144 => '0%'] as $y => $label)
                    <line x1="24" y1="{{ $y }}" x2="616" y2="{{ $y }}" stroke="#e4e4e7" stroke-dasharray="3 5"/><text x="24" y="{{ $y - 5 }}" fill="#71717a" font-size="10">{{ $label }}</text>
                @endforeach
                <polyline data-battery-line points="{{ $trendPoints }}" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <p data-battery-empty class="empty-state" @if (count($trend) >= 2) hidden @endif>Two robot reports are needed to show a trend.</p>
            <p class="mt-3 text-xs leading-5 text-zinc-500" data-battery-range>Reports are ordered by time; gaps between reports can vary.</p>
        </section>
        <section class="workspace-panel" aria-labelledby="recent-reviews-heading">
            <div class="flex items-center justify-between gap-3"><div><h3 id="recent-reviews-heading" class="font-semibold">Recent camera observations</h3><p class="mt-1 text-xs text-zinc-500">Saved device and robot camera records</p></div><a href="{{ route('robot.dashboard.logs', ['robot_id' => $robotId]) }}" class="icon-button" aria-label="View camera records"><img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></div>
            <div data-insight-reviews class="mt-4 divide-y divide-zinc-100">
                @forelse ($workspaceInsights['recent_observations'] as $review)
                    <div class="flex min-w-0 items-start justify-between gap-3 py-3"><div class="min-w-0"><p class="break-words text-sm font-semibold">{{ $review['label'] }}</p><p class="mt-1 break-words text-xs text-zinc-500">{{ $review['category'] }} · {{ $review['source'] }}</p></div><span class="shrink-0 text-xs text-zinc-500">{{ $review['confidence'] !== null ? round($review['confidence']).'%' : '—' }}</span></div>
                @empty
                    <p class="empty-state">Your saved camera observations will appear here.</p>
                @endforelse
            </div>
        </section>
    </div>
</section>
