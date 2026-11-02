<section class="page-heading" aria-label="Page status">
    <div class="min-w-0">
        <p class="mb-1 text-xs font-medium tracking-wider text-emerald-800 uppercase">RobotSys workspace</p>
        <h1 class="text-2xl font-semibold leading-tight">{{ $activePageTitle }}</h1>
        <p class="mt-2 text-sm text-zinc-500">{{ $dashboardPages[$activePage]['description'] }}</p>
        @if ($activePage !== 'logs')
            <form method="GET" action="{{ route($dashboardPages[$activePage]['route']) }}" class="mt-4 flex items-center gap-2" data-robot-switcher>
                <label for="workspaceRobot" class="shrink-0 whitespace-nowrap text-xs font-medium text-zinc-500">Active robot</label>
                <select id="workspaceRobot" name="robot_id" class="form-input w-36 max-w-full text-xs" aria-label="Active robot">
                    @foreach ($robotOptions as $option)
                        <option value="{{ $option }}" @selected($robotId === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <button type="submit" class="icon-button" aria-label="Switch robot"><img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></button>
            </form>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div class="text-xs text-zinc-500">
            <span class="block sm:text-right">Last telemetry</span>
            <span id="last_updated" class="mt-1 block font-medium text-zinc-700">{{ $lastUpdated }}</span>
        </div>
        <span id="connection_status" class="status-badge bg-zinc-100 text-zinc-600 ring-zinc-200" role="status">
            <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
            Connecting
        </span>
        <button id="refreshDashboardButton" type="button" class="icon-button" title="Refresh dashboard" aria-label="Refresh dashboard">
            <img src="{{ asset('icons/refresh-cw.svg') }}" class="size-4" alt="" aria-hidden="true">
        </button>
        <span id="refreshStatus" class="sr-only" role="status" aria-live="polite"></span>
    </div>
</section>
