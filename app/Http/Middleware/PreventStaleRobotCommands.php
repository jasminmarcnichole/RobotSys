<?php

namespace App\Http\Middleware;

use App\Models\RobotLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventStaleRobotCommands
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Allow START, STOP, and RESET commands in Direct Wi-Fi AP mode (192.168.4.1)
        // even when periodic database telemetry is not active.
        if (config('services.robot.control_url')) {
            return $next($request);
        }

        $robotId = $request->input('robot_id') ?? 'ROBOT-001';

        if (in_array($request->input('command'), ['START', 'RESET'], true) && is_string($robotId)) {
            $connected = RobotLog::query()
                ->telemetry()
                ->where('robot_id', $robotId)
                ->whereBetween('created_at', [now()->subSeconds(15), now()])
                ->exists();

            if (! $connected) {
                return response()->json(['message' => 'No recent robot telemetry. Reconnect the robot before starting or resetting it.'], 409);
            }
        }

        return $next($request);
    }
}
