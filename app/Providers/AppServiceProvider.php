<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-admin', fn (User $user): bool => $user->is_admin);

        RateLimiter::for('robot-ingest', fn (Request $request) => Limit::perMinute(120)->by($this->rateLimitKey($request)));
        RateLimiter::for('robot-read', fn (Request $request) => Limit::perMinute(180)->by($this->rateLimitKey($request)));
        RateLimiter::for('robot-command', fn (Request $request) => Limit::perMinute(60)->by($this->rateLimitKey($request)));
    }

    private function rateLimitKey(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}
