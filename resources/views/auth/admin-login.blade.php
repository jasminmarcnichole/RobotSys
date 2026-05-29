<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - {{ config('app.name', 'RobotSys') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 font-sans text-zinc-950 antialiased">
    <main class="grid min-h-screen lg:grid-cols-[0.9fr_1.1fr]">
        <section class="hidden bg-[linear-gradient(135deg,#12302f,#17324d_54%,#0f766e)] p-10 text-white lg:flex lg:flex-col lg:justify-between">
            <a href="{{ route('login') }}" class="flex items-center gap-3">
                <span class="flex size-11 items-center justify-center rounded-lg bg-white/12 text-sm font-bold ring-1 ring-white/20">RS</span>
                <span>
                    <span class="block text-lg font-bold leading-5">RobotSys</span>
                    <span class="block text-sm text-white/68">Admin operations console</span>
                </span>
            </a>

            <div class="max-w-xl">
                <p class="mb-4 text-sm font-semibold uppercase text-white/68">Secure control access</p>
                <h1 class="text-4xl font-bold leading-tight">Autonomous robot monitoring for authorized admins.</h1>
                <p class="mt-5 max-w-lg text-base leading-7 text-white/72">
                    Review telemetry, camera detection, waste classification, and command dispatch from a protected dashboard.
                </p>
            </div>

            <div class="grid grid-cols-3 gap-3 text-sm">
                <div class="rounded-lg border border-white/14 bg-white/10 p-4">
                    <span class="block text-xs uppercase text-white/58">Status</span>
                    <span class="mt-2 block font-semibold">Live telemetry</span>
                </div>
                <div class="rounded-lg border border-white/14 bg-white/10 p-4">
                    <span class="block text-xs uppercase text-white/58">Vision</span>
                    <span class="mt-2 block font-semibold">Camera scans</span>
                </div>
                <div class="rounded-lg border border-white/14 bg-white/10 p-4">
                    <span class="block text-xs uppercase text-white/58">Control</span>
                    <span class="mt-2 block font-semibold">Robot commands</span>
                </div>
            </div>
        </section>

        <section class="flex items-center justify-center bg-slate-50 px-5 py-10">
            <div class="w-full max-w-md">
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    <span class="flex size-11 items-center justify-center rounded-lg bg-teal-700 text-sm font-bold text-white">RS</span>
                    <span>
                        <span class="block text-lg font-bold leading-5">RobotSys</span>
                        <span class="block text-sm text-slate-500">Admin operations console</span>
                    </span>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="mb-7">
                        <h2 class="text-2xl font-bold">Admin Login</h2>
                        <p class="mt-2 text-sm text-slate-500">Use an active admin account to continue.</p>
                    </div>

                    <form method="POST" action="{{ route('admin.login.store') }}" class="space-y-5">
                        @csrf

                        <div>
                            <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Email address</label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                required
                                autofocus
                                class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-teal-700 focus:ring-2 focus:ring-teal-700/15"
                            >
                            @error('email')
                                <p class="mt-2 text-sm font-medium text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">Password</label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                required
                                class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-teal-700 focus:ring-2 focus:ring-teal-700/15"
                            >
                            @error('password')
                                <p class="mt-2 text-sm font-medium text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <label for="remember" class="flex items-center gap-2 text-sm font-medium text-slate-600">
                                <input
                                    id="remember"
                                    name="remember"
                                    type="checkbox"
                                    value="1"
                                    @checked(old('remember'))
                                    class="size-4 rounded border-slate-300 text-teal-700 focus:ring-teal-700"
                                >
                                Remember me
                            </label>
                        </div>

                        <button
                            type="submit"
                            class="flex min-h-11 w-full items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-700/25"
                        >
                            Sign in
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
