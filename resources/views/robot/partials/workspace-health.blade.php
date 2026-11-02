<details class="workspace-health" data-health-panel>
    <summary class="flex min-h-12 list-none flex-wrap items-center justify-between gap-3 px-4 py-3">
        <span class="flex items-center gap-2 text-sm font-medium"><span data-health-dot class="size-2 shrink-0 rounded-full" aria-hidden="true"></span><span data-health-summary>Checking workspace status</span></span>
        <span class="flex items-center gap-3 text-xs text-zinc-500"><span data-health-count></span><img src="{{ asset('icons/chevron-down.svg') }}" class="details-chevron size-4" alt="" aria-hidden="true"></span>
    </summary>
    <ul data-health-items class="grid gap-3 border-t border-zinc-200 px-4 py-4 md:grid-cols-2" aria-label="Workspace health signals" aria-live="polite"><li class="text-sm text-zinc-500">Waiting for robot telemetry.</li></ul>
</details>
