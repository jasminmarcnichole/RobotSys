@extends('auth.portal')

@section('title', 'Admin Login')

@section('content')
    <section id="system-overview" class="portal-hero" aria-labelledby="welcome-heading">
        <div class="portal-container grid items-center gap-12 py-12 sm:py-16 lg:grid-cols-[minmax(0,1.2fr)_minmax(340px,0.8fr)] lg:gap-16 lg:py-20">
            <div class="min-w-0">
                <p class="portal-eyebrow"><span class="size-2 rounded-full bg-emerald-600" aria-hidden="true"></span>A clearer view of every operation</p>
                <h1 id="welcome-heading" class="mt-6 max-w-2xl text-4xl font-semibold leading-[1.12] tracking-tight sm:text-5xl lg:text-6xl">A cleaner space.<br><span class="text-emerald-800">A smarter workspace.</span></h1>
                <p class="mt-6 max-w-lg text-base leading-8 text-zinc-600">Meet RobotSys. One place to monitor your robot, review camera detections, and keep waste collection records organized.</p>
                <div class="mt-7 flex flex-wrap items-center gap-4">
                    <a href="{{ route('manual') }}" class="button button-primary min-h-12 px-5">Explore the operator manual<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4 invert" alt="" aria-hidden="true"></a>
                    <a href="#how-it-works" class="text-link min-h-12">See how it works<img src="{{ asset('icons/chevron-down.svg') }}" class="size-4" alt="" aria-hidden="true"></a>
                </div>
                <div class="portal-workflow mt-10" aria-label="RobotSys workflow">
                    @foreach ([['camera', 'Connect', 'Choose a camera'], ['scan-line', 'Review', 'Confirm what you see'], ['clipboard-list', 'Record', 'Keep a clear history']] as [$icon, $label, $description])
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-emerald-900/10 bg-white/80"><img src="{{ asset('icons/'.$icon.'.svg') }}" class="size-4" alt="" aria-hidden="true"></span>
                            <div><p class="text-sm font-semibold text-zinc-800">{{ $label }}</p><p class="mt-1 text-xs leading-5 text-zinc-500">{{ $description }}</p></div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-5 flex items-center gap-2 text-xs leading-5 text-zinc-500"><img src="{{ asset('icons/circle-help.svg') }}" class="size-3.5 shrink-0" alt="" aria-hidden="true">New here? Read the manual before starting your first session.</p>
            </div>
            <section id="sign-in" class="login-panel" aria-labelledby="login-heading">
                <div class="flex items-center justify-between gap-3"><span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-50 ring-1 ring-emerald-100"><img src="{{ asset('icons/shield-check.svg') }}" class="size-6" alt="" aria-hidden="true"></span><span class="text-xs font-medium text-zinc-500">Admin Login</span></div>
                <h2 id="login-heading" class="mt-6 text-2xl font-semibold tracking-tight">Welcome back.</h2>
                <p class="mt-2 text-sm leading-6 text-zinc-500">Sign in to your operations workspace.</p>
                @if ($errors->any())
                    <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                        <p class="font-semibold">Unable to sign in</p>
                        <ul class="mt-1 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" action="{{ route('admin.login.store') }}" class="mt-7 flex flex-col gap-5" data-login-form>
                    @csrf
                    <div class="field-group">
                        <label for="email" class="field-label">Email address</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" inputmode="email" spellcheck="false" autocapitalize="none" required placeholder="you@example.com" @error('email') aria-invalid="true" aria-describedby="email-error" autofocus @enderror class="form-input min-h-12">
                        @error('email')<p id="email-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label for="password" class="field-label">Password</label>
                        <div class="relative">
                            <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Enter your password" @error('password') aria-invalid="true" aria-describedby="password-error" @enderror class="form-input min-h-12 pr-16">
                            <button type="button" class="password-toggle hidden" data-password-toggle aria-controls="password" aria-pressed="false" aria-label="Show password">Show</button>
                        </div>
                        @error('password')<p id="password-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <label for="remember" class="flex min-h-8 w-fit items-center gap-2.5 text-sm text-zinc-600"><input id="remember" name="remember" type="checkbox" value="1" @checked(old('remember')) class="size-4 rounded border-zinc-300">Remember me</label>
                    <button type="submit" class="button button-primary min-h-12 w-full" data-login-submit><span data-login-label>Sign in to workspace</span><img src="{{ asset('icons/arrow-right.svg') }}" class="size-4 invert" alt="" aria-hidden="true"></button>
                </form>
                <div class="mt-6 border-t border-zinc-100 pt-5 text-xs leading-6 text-zinc-500"><p>Need an account or help signing in? Contact your system administrator.</p><a href="{{ route('manual') }}#getting-started" class="text-link mt-2 text-xs">Read the getting started guide<img src="{{ asset('icons/arrow-right.svg') }}" class="size-3.5" alt="" aria-hidden="true"></a></div>
            </section>
        </div>
    </section>
    <section class="portal-container py-14 sm:py-20" aria-labelledby="workspace-heading">
        <div class="max-w-2xl"><p class="portal-eyebrow">Your operations, connected</p><h2 id="workspace-heading" class="portal-section-title">From the camera to the collection record.</h2><p class="mt-4 text-base leading-7 text-zinc-500">A focused workspace that helps operators see what is happening and decide what comes next.</p></div>
        <div class="mt-8 grid gap-5 md:grid-cols-3">
            @foreach ([['activity', '01', 'Monitor the robot', 'Check reported battery, movement, pickup counts, and collection activity from one overview.'], ['scan-line', '02', 'Review camera detections', 'Use a laptop camera or upload an image. Inspect detected objects and confirm the waste category before saving.'], ['clipboard-list', '03', 'Follow the history', 'Find reviewed detections and robot activity, filter records, and export logs for your reports.']] as [$icon, $number, $heading, $description])
                <article class="portal-feature"><div class="flex items-center justify-between"><span class="portal-icon"><img src="{{ asset('icons/'.$icon.'.svg') }}" class="size-5" alt="" aria-hidden="true"></span><span class="font-mono text-xs text-zinc-400">{{ $number }}</span></div><h3 class="mt-6 text-lg font-semibold tracking-tight">{{ $heading }}</h3><p class="mt-3 text-sm leading-7 text-zinc-500">{{ $description }}</p></article>
            @endforeach
        </div>
    </section>
    <section id="how-it-works" class="border-y border-zinc-200 bg-white" aria-labelledby="workflow-heading">
        <div class="portal-container grid gap-10 py-14 sm:py-16 lg:grid-cols-[0.8fr_1.2fr] lg:gap-20">
            <div><p class="portal-eyebrow">A simple place to start</p><h2 id="workflow-heading" class="portal-section-title">Your first session,<br>step by step.</h2><p class="mt-4 text-sm leading-7 text-zinc-500">You can explore the camera workflow with your laptop before connecting robot hardware.</p><a href="{{ route('manual') }}#camera" class="text-link mt-5">Open the camera guide<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></div>
            <ol class="divide-y divide-zinc-100">
                @foreach ([['Open your workspace', 'Sign in with your administrator account and open Camera Detection from the navigation.'], ['Choose an image source', 'Select Use Laptop Camera and allow camera access, or upload a photo to try a single image.'], ['Review, confirm, and save', 'Review a frame, select an object, confirm its material category, and save the record to your history.']] as [$heading, $description])
                    <li class="flex gap-5 py-5 first:pt-0 last:pb-0"><span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-50 font-mono text-xs font-semibold text-emerald-800">0{{ $loop->iteration }}</span><div><h3 class="text-base font-semibold">{{ $heading }}</h3><p class="mt-2 text-sm leading-7 text-zinc-500">{{ $description }}</p></div></li>
                @endforeach
            </ol>
        </div>
    </section>
    <section class="portal-container py-14 sm:py-20" aria-labelledby="help-heading">
        <div class="flex flex-wrap items-end justify-between gap-5"><div><p class="portal-eyebrow">A little guidance goes a long way</p><h2 id="help-heading" class="portal-section-title">Get to know your system.</h2></div><a href="{{ route('manual') }}" class="text-link">View the full manual<img src="{{ asset('icons/arrow-right.svg') }}" class="size-4" alt="" aria-hidden="true"></a></div>
        <div class="mt-8 grid gap-4 md:grid-cols-3">
            @foreach ([['camera', 'camera', 'Camera & detection', 'Connect a camera and review your first frame.'], ['command', 'robot-controls', 'Robot operations', 'Understand telemetry and command statuses.'], ['circle-help', 'troubleshooting', 'Troubleshooting', 'Find help with cameras, connections, and access.']] as [$icon, $anchor, $heading, $description])
                <a href="{{ route('manual') }}#{{ $anchor }}" class="portal-guide-link"><span class="portal-icon"><img src="{{ asset('icons/'.$icon.'.svg') }}" class="size-5" alt="" aria-hidden="true"></span><span class="min-w-0 flex-1"><span class="block text-sm font-semibold">{{ $heading }}</span><span class="mt-1 block text-xs leading-6 text-zinc-500">{{ $description }}</span></span><img src="{{ asset('icons/arrow-right.svg') }}" class="size-4 shrink-0" alt="" aria-hidden="true"></a>
            @endforeach
        </div>
    </section>
@endsection
