<header class="portal-header">
    <div class="portal-container flex min-h-20 flex-wrap items-center justify-between gap-3 py-4">
        <a href="{{ auth()->check() ? route('robot.dashboard') : route('login') }}" class="inline-flex shrink-0 items-center gap-3" aria-label="RobotSys home">
            <span class="brand-mark">RS</span>
            <span class="text-xl font-semibold tracking-tight">RobotSys<span class="mt-0.5 block text-[10px] font-medium tracking-[0.16em] text-zinc-500 uppercase">Waste collection & monitoring</span></span>
        </a>
        <nav aria-label="Main navigation" class="flex items-center gap-1 sm:gap-3">
            @guest
                <a href="{{ route('login') }}#system-overview" class="portal-nav-link hidden sm:inline-flex">The system</a>
            @endguest
            <a href="{{ route('manual') }}" class="portal-nav-link" @if (request()->routeIs('manual')) aria-current="page" @endif>Operator manual</a>
            @auth
                <a href="{{ route('robot.dashboard') }}" class="button button-primary">Open workspace<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4 invert" alt="" aria-hidden="true"></a>
            @else
                <a href="{{ route('login') }}#sign-in" class="button button-secondary">Sign in<img src="{{ asset('icons/arrow-right.svg') }}" class="hidden size-4 sm:block" alt="" aria-hidden="true"></a>
            @endauth
        </nav>
    </div>
</header>
