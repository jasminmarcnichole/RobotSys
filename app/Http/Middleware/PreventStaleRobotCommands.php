<?php

namespace App\Http\Middleware;

use App\Models\RobotLog;
use Closure;
use Illuminate\Database\Eloquent\Builder;
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
        $robotId = $request->input('robot_id') ?? 'ROBOT-001';

        if (in_array($request->input('command'), ['START', 'RESET'], true) && is_string($robotId)) {
            $connected = RobotLog::query()
                ->where('robot_id', $robotId)
                ->where(function (Builder $query): Builder {
                    return $query->whereNull('detection_source')->orWhereIn('detection_source', ['robot_sensor', 'robot_camera']);
                })
                ->whereBetween('created_at', [now()->subSeconds(15), now()])
                ->exists();

            if (! $connected) {
                return response()->json(['message' => 'No recent robot telemetry. Reconnect the robot before starting or resetting it.'], 409);
            }
        }

        return $next($request);
    }
}
