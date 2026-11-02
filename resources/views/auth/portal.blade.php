<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('auth.partials.app-meta')
    <meta name="description" content="Explore RobotSys: your workspace for robot monitoring, camera review, and waste collection records.">
    <title>@yield('title') | {{ config('app.name', 'RobotSys') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="portal-body font-sans text-zinc-950 antialiased">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    @include('auth.partials.portal-header')
    @include('auth.partials.app-install')
    <main id="main-content" tabindex="-1">@yield('content')</main>
    <footer class="portal-container flex flex-wrap items-center justify-between gap-4 border-t border-zinc-200 py-7 text-xs text-zinc-500">
        <span><span class="font-semibold text-zinc-700">RobotSys</span><span class="mx-2" aria-hidden="true">/</span>RobotSys operations</span>
        <div class="flex items-center gap-5">
            <a href="{{ route('manual') }}" class="portal-footer-link">Operator manual</a>
            <a href="{{ auth()->check() ? route('robot.dashboard') : route('login').'#sign-in' }}" class="portal-footer-link">{{ auth()->check() ? 'Open workspace' : 'Administrator access' }}</a>
        </div>
    </footer>
</body>
</html>
