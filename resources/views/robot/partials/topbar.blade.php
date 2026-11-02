@php
    $navigationIcons = [
        'control' => 'command',
        'overview' => 'layout-dashboard',
        'camera' => 'scan-line',
        'capabilities' => 'cpu',
        'waste' => 'trash-2',
        'commands' => 'command',
        'evaluation' => 'chart-no-axes-combined',
        'logs' => 'clipboard-list',
    ];
@endphp

<aside class="app-sidebar" aria-label="Sidebar">
    <a href="{{ route('robot.dashboard', ['robot_id' => $robotId]) }}" class="flex min-h-16 items-center gap-3 border-b border-zinc-200 px-5">
        <span class="brand-mark size-9 rounded-xl text-xs">RS</span>
        <span class="text-lg font-semibold">RobotSys<span class="block text-xs font-normal text-zinc-500">Operations console</span></span>
    </a>
    <nav class="flex flex-col gap-1 px-3 py-6" aria-label="Dashboard pages">
        <p class="px-3 pb-3 text-xs font-medium text-zinc-500">Workspace</p>
        @foreach ($dashboardPages as $pageKey => $page)
            <a href="{{ route($page['route'], ['robot_id' => $robotId]) }}" class="nav-link {{ $activePage === $pageKey ? 'is-active' : '' }}" @if ($activePage === $pageKey) aria-current="page" @endif>
                <img src="{{ asset('icons/'.($navigationIcons[$pageKey] ?? 'activity').'.svg') }}" class="size-4 shrink-0" alt="" aria-hidden="true">
                <span>{{ $page['label'] }}</span>
            </a>
        @endforeach
    </nav>
    <div class="mt-auto px-3 pb-4">
        <a href="{{ route('manual') }}" class="nav-link"><img src="{{ asset('icons/circle-help.svg') }}" class="size-4" alt="" aria-hidden="true">Operator manual</a>
    </div>
    <div class="border-t border-zinc-200 px-5 py-5">
        <div class="flex items-center gap-2 text-sm font-medium">
            <img src="{{ asset('icons/shield-check.svg') }}" class="size-4" alt="" aria-hidden="true">
            Admin workspace
        </div>
        <p class="mt-1 break-words text-xs text-zinc-500">{{ auth()->user()?->name ?? 'Administrator' }}</p>
    </div>
</aside>

<header class="app-topbar">
    <div class="flex min-w-0 items-center gap-3">
        <details class="mobile-navigation lg:hidden">
            <summary class="icon-button" title="Open navigation" aria-label="Open navigation">
                <img src="{{ asset('icons/menu.svg') }}" class="size-5" alt="" aria-hidden="true">
            </summary>
            <nav class="mobile-navigation-panel" aria-label="Dashboard pages">
                <a href="{{ route('robot.dashboard', ['robot_id' => $robotId]) }}" class="mb-3 block px-3 text-lg font-semibold">RobotSys</a>
                @foreach ($dashboardPages as $pageKey => $page)
                    <a href="{{ route($page['route'], ['robot_id' => $robotId]) }}" class="nav-link {{ $activePage === $pageKey ? 'is-active' : '' }}" @if ($activePage === $pageKey) aria-current="page" @endif>
                        <img src="{{ asset('icons/'.($navigationIcons[$pageKey] ?? 'activity').'.svg') }}" class="size-4 shrink-0" alt="" aria-hidden="true">
                        <span>{{ $page['label'] }}</span>
                    </a>
                @endforeach
                <a href="{{ route('manual') }}" class="nav-link"><img src="{{ asset('icons/circle-help.svg') }}" class="size-4" alt="" aria-hidden="true">Operator manual</a>
            </nav>
        </details>
        <span class="hidden text-sm text-zinc-500 sm:inline">Operations</span>
        <span class="hidden text-zinc-300 sm:inline" aria-hidden="true">/</span>
        <span class="truncate text-sm font-medium">{{ $activePageTitle }}</span>
    </div>
    <div class="flex min-w-0 items-center gap-2 sm:gap-4">
        <button type="button" class="workspace-search-button" data-workspace-search-open aria-label="Search workspace" aria-haspopup="dialog" aria-controls="workspaceSearch"><img src="{{ asset('icons/search.svg') }}" class="size-4" alt="" aria-hidden="true"><span class="hidden xl:inline">Search workspace</span><kbd class="hidden text-[10px] text-zinc-400 xl:inline">Ctrl K</kbd></button>
        <span class="robot-identity max-w-32 truncate font-mono text-xs font-medium text-zinc-600 sm:max-w-52">{{ $robotId }}</span>
        <span id="movement_status" class="status-badge {{ $statusToneClass }}" role="status">
            <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
            <span data-status-label>{{ $latestStatus }}</span>
        </span>
        <details class="account-menu">
            <summary class="icon-button" title="Account menu" aria-label="Account menu">
                <img src="{{ asset('icons/shield-check.svg') }}" class="size-4" alt="" aria-hidden="true">
                <img src="{{ asset('icons/chevron-down.svg') }}" class="size-3" alt="" aria-hidden="true">
            </summary>
            <div class="account-menu-panel">
                <p class="break-words text-sm font-semibold">{{ auth()->user()?->name ?? 'Administrator' }}</p>
                <p class="mt-1 break-all text-xs text-zinc-500">{{ auth()->user()?->email }}</p>
                <form method="POST" action="{{ route('admin.logout') }}" class="mt-4 border-t border-zinc-200 pt-3">
                    @csrf
                    <button type="submit" class="button button-secondary w-full justify-start">
                        <img src="{{ asset('icons/log-out.svg') }}" class="size-4" alt="" aria-hidden="true">
                        Logout
                    </button>
                </form>
            </div>
        </details>
    </div>
</header>
