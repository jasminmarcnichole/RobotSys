<section class="page-heading" aria-label="Page status">
    <div class="min-w-0">
        <p class="mb-1 text-[11px] font-semibold tracking-wider text-emerald-800 uppercase">RobotSys workspace</p>
        <h1 class="text-2xl font-semibold leading-tight tracking-tight text-zinc-950">{{ $activePageTitle }}</h1>
        <p class="mt-1.5 text-sm text-zinc-500">{{ $dashboardPages[$activePage]['description'] }}</p>
        @if ($activePage !== 'logs')
            <form method="GET" action="{{ route($dashboardPages[$activePage]['route']) }}" class="mt-3.5 inline-flex items-center gap-2 rounded-xl border border-zinc-200/80 bg-white p-1 pl-3 shadow-2xs" data-robot-switcher>
                <label for="workspaceRobot" class="shrink-0 whitespace-nowrap text-xs font-medium text-zinc-500">Active robot</label>
                <select id="workspaceRobot" name="robot_id" class="form-input min-h-8 w-36 max-w-full border-0 bg-transparent py-1 text-xs font-medium focus:outline-none" aria-label="Active robot" onchange="this.form.requestSubmit()">
                    @foreach ($robotOptions as $option)
                        <option value="{{ $option }}" @selected($robotId === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <button type="submit" class="icon-button size-8 min-h-8 min-w-8 border-zinc-200/70 bg-zinc-50" aria-label="Switch robot"><img src="{{ asset('icons/arrow-right.svg') }}" class="size-3.5" alt="" aria-hidden="true"></button>
            </form>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-2xl border border-zinc-200/80 bg-white px-4 py-2.5 shadow-2xs">
        <div class="text-xs text-zinc-500">
            <span class="block sm:text-right">Last telemetry</span>
            <span id="last_updated" class="mt-0.5 block font-semibold text-zinc-800">{{ $lastUpdated }}</span>
        </div>
        <span class="hidden h-7 w-px bg-zinc-200 sm:block" aria-hidden="true"></span>
        <span id="connection_status" class="status-badge bg-zinc-100 text-zinc-600 ring-zinc-200" role="status">
            <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
            Connecting
        </span>
        <button id="refreshDashboardButton" type="button" class="icon-button size-9 min-h-9 min-w-9" title="Refresh dashboard" aria-label="Refresh dashboard">
            <img src="{{ asset('icons/refresh-cw.svg') }}" class="size-4" alt="" aria-hidden="true">
        </button>
        <span id="refreshStatus" class="sr-only" role="status" aria-live="polite"></span>
    </div>
</section>
